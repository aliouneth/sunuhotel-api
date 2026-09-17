<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Exceptions\ValidationException as DomainValidationException;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Booking engine.
 *
 * All mutations run inside a DB transaction that:
 *   1. locks the involved rooms (pessimistic row lock),
 *   2. re-checks availability for the requested windows,
 *   3. writes the Booking + BookingRoom rows.
 *
 * The unique index (room_id, check_in, check_out, active=1) on booking_rooms is
 * the final backstop against a lost-check overbooking under load.
 */
final class BookingService
{
    public function __construct(
        private readonly RateService $rates,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @param array{
     *     guest_id?: int,
     *     guest?: array{
     *         first_name: string,
     *         last_name: string,
     *         email?: string|null,
     *         phone?: string|null,
     *         nationality?: string|null,
     *         notes?: string|null,
     *     },
     *     check_in: string,
     *     check_out: string,
     *     rooms: array<int, array{room_id: int, rate_plan_id?: int}>,
     *     adults?: int,
     *     children?: int,
     *     source?: string,
     *     discount_cents?: int,
     *     notes?: string,
     *     status?: string,
     * } $payload
     */
    public function create(Hotel $hotel, ?User $actor, array $payload): Booking
    {
        $checkIn = Carbon::parse($payload['check_in']);
        $checkOut = Carbon::parse($payload['check_out']);

        $this->assertValidWindow($checkIn, $checkOut);
        $guest = $this->resolveGuest($hotel, $payload, $actor);

        $discount = (int) ($payload['discount_cents'] ?? 0);

        $this->assertRoomsBelong($payload['rooms'], $hotel->id);

        try {
            return \DB::transaction(function () use ($hotel, $actor, $payload, $checkIn, $checkOut, $discount, $guest) {
                // Pre-lock rooms to serialise booking creation for hot rooms.
                $this->lockRooms(array_column($payload['rooms'], 'room_id'));

                $this->assertRoomsFree($payload['rooms'], $hotel->id, $checkIn, $checkOut);

                $booking = Booking::create([
                    'hotel_id' => $hotel->id,
                    'booking_number' => $hotel->nextBookingNumber(),
                    'guest_id' => $guest->id,
                    'status' => $payload['status'] ?? 'confirmed',
                    'check_in' => $checkIn->toDateString(),
                    'check_out' => $checkOut->toDateString(),
                    'adults' => (int) ($payload['adults'] ?? 1),
                    'children' => (int) ($payload['children'] ?? 0),
                    'source' => $payload['source'] ?? 'front_desk',
                    'discount_cents' => $discount,
                    'notes' => $payload['notes'] ?? null,
                    'created_by' => $actor?->id,
                    'created_by_name' => $actor?->name ?? 'Web request',
                ]);

                $subtotal = 0;
                foreach ($payload['rooms'] as $line) {
                    $room = Room::query()->findOrFail($line['room_id']);
                    $nights = $checkIn->diffInDays($checkOut);

                    // Rooms with an explicit daily rate are priced flat; others
                    // fall back to the rate plan snapshot.
                    if ($room->daily_rate_cents !== null) {
                        $nightly = (int) $room->daily_rate_cents;
                        $lineTotal = $nights * $nightly;
                        $planId = null;
                    } else {
                        $plan = $this->resolvePlan($room, $line['rate_plan_id'] ?? null);
                        $lineTotal = $this->rates->stayTotalCents($plan, $checkIn->toDateString(), $checkOut->toDateString());
                        $nightly = (int) round($lineTotal / max(1, $nights));
                        $planId = $plan->id;
                    }

                    BookingRoom::create([
                        'hotel_id' => $hotel->id,
                        'booking_id' => $booking->id,
                        'room_id' => $room->id,
                        'rate_plan_id' => $planId,
                        'check_in' => $checkIn->toDateString(),
                        'check_out' => $checkOut->toDateString(),
                        'nights' => $nights,
                        'nightly_rate_cents' => $nightly,
                        'line_total_cents' => $lineTotal,
                        'active' => true,
                        'active_key' => $this->allocationKey($room->id, $checkIn->toDateString(), $checkOut->toDateString()),
                    ]);

                    if ($room->status === 'available') {
                        $room->update(['status' => 'dirty']);
                    }

                    $subtotal += $lineTotal;
                }

                $tax = $this->taxCents($hotel, $subtotal, array_column($payload['rooms'], 'rate_plan_id' ?? []));
                $total = max(0, $subtotal + $tax - $discount);

                $booking->update([
                    'subtotal_cents' => $subtotal,
                    'tax_cents' => $tax,
                    'total_cents' => $total,
                ]);

                $booking->refresh();
                $booking->load(['guest', 'rooms.room']);

                AuditLogger::critical($booking, 'booking.created', [
                    'rooms' => count($payload['rooms']),
                    'total_cents' => $total,
                ]);

                return $booking;
            }, 3);
        } catch (BookingConflictException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->isOverbookingIntegrityError($e)) {
                throw new BookingConflictException(
                    'One or more rooms were just booked for part of this window.',
                    'ROOM_CONFLICT',
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * Move a booking forward in its workflow.
     */
    public function checkIn(Booking $booking, User $actor): Booking
    {
        $this->assertStatus($booking, ['confirmed']);
        $this->assertCheckInIsOpen($booking);

        return \DB::transaction(function () use ($booking, $actor) {
            $booking->update(['status' => 'checked_in']);
            $now = now();

            $booking->rooms->each(function (BookingRoom $line) use ($now, $actor) {
                $line->update([
                    'checked_in_at' => $now,
                    'checked_in_by_name' => $actor->name,
                ]);
                $line->room()->update(['status' => 'occupied']);
            });

            AuditLogger::critical($booking, 'booking.checked_in');

            return $booking->refresh();
        });
    }

    public function checkOut(Booking $booking, User $actor): Booking
    {
        $this->assertStatus($booking, ['checked_in']);

        return \DB::transaction(function () use ($booking, $actor) {
            $booking->update(['status' => 'checked_out']);
            $now = now();

            $booking->rooms->each(function (BookingRoom $line) use ($now) {
                $line->update(['checked_out_at' => $now]);
                $line->room()->update(['status' => 'dirty']);
            });

            AuditLogger::critical($booking, 'booking.checked_out');

            return $booking->refresh();
        });
    }

    public function cancel(Booking $booking, User $actor, ?string $reason = null, bool $noShow = false): Booking
    {
        $this->assertStatus($booking, ['pending', 'confirmed', 'checked_in']);

        return \DB::transaction(function () use ($booking, $actor, $reason, $noShow) {
            $booking->update([
                'status' => $noShow ? 'no_show' : 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            $booking->rooms->each(function (BookingRoom $line) {
                $line->update(['active' => false, 'active_key' => null]);
                $line->room()->where('status', 'occupied')->update(['status' => 'available']);
            });

            AuditLogger::critical($booking, 'booking.cancelled', [
                'reason' => $reason,
                'no_show' => $noShow,
            ]);

            return $booking->refresh();
        });
    }

    /**
     * Review approval: a hotel user accepts a reservation request (pending -> confirmed).
     * The held allocations stay active; only the status changes.
     */
    public function confirm(Booking $booking, User $actor): Booking
    {
        $this->assertStatus($booking, ['pending']);

        return \DB::transaction(function () use ($booking, $actor) {
            $booking->update([
                'status' => 'confirmed',
                'created_by' => $booking->created_by ?? $actor->id,
            ]);

            AuditLogger::critical($booking, 'booking.confirmed', [
                'by' => $actor->name,
            ]);

            return $booking->refresh();
        });
    }

    /**
     * Modify stay dates or allocated rooms. Existing allocations that shrink
     * are deactivated and new overlaps are guarded.
     */
    public function modify(Booking $booking, User $actor, array $payload): Booking
    {
        $this->assertStatus($booking, ['pending', 'confirmed']);

        $checkIn = Carbon::parse($payload['check_in'] ?? $booking->check_in);
        $checkOut = Carbon::parse($payload['check_out'] ?? $booking->check_out);
        $newRoomIds = $payload['rooms'] ?? $booking->rooms()->pluck('room_id')->all();

        $this->assertValidWindow($checkIn, $checkOut);

        return \DB::transaction(function () use ($booking, $actor, $payload, $checkIn, $checkOut, $newRoomIds) {
            $this->lockRooms($newRoomIds);
            $this->assertRoomsFree($newRoomIds, $booking->hotel_id, $checkIn, $checkOut, (int) $booking->id);

            $booking->rooms->each(fn (BookingRoom $line) => $line->update(['active' => false, 'active_key' => null]));

            $booking->update([
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'adults' => (int) ($payload['adults'] ?? $booking->adults),
                'children' => (int) ($payload['children'] ?? $booking->children),
                'notes' => $payload['notes'] ?? $booking->notes,
            ]);

            $subtotal = 0;
            foreach ($newRoomIds as $roomId) {
                $room = Room::query()->findOrFail($roomId);
                $nights = $checkIn->diffInDays($checkOut);

                if ($room->daily_rate_cents !== null) {
                    $nightly = (int) $room->daily_rate_cents;
                    $lineTotal = $nights * $nightly;
                    $planId = null;
                } else {
                    $plan = $this->resolvePlan($room, null);
                    $lineTotal = $this->rates->stayTotalCents($plan, $checkIn->toDateString(), $checkOut->toDateString());
                    $nightly = (int) round($lineTotal / max(1, $nights));
                    $planId = $plan->id;
                }

                $booking->rooms()->create([
                    'hotel_id' => $booking->hotel_id,
                    'room_id' => $room->id,
                    'rate_plan_id' => $planId,
                    'check_in' => $checkIn->toDateString(),
                    'check_out' => $checkOut->toDateString(),
                    'nights' => $nights,
                    'nightly_rate_cents' => $nightly,
                    'line_total_cents' => $lineTotal,
                    'active' => true,
                    'active_key' => $this->allocationKey($room->id, $checkIn->toDateString(), $checkOut->toDateString()),
                ]);

                $subtotal += $lineTotal;
            }

            $hotel = $booking->hotel()->first();
            $tax = $this->taxCents($hotel, $subtotal, []);
            $discount = $booking->discount_cents;
            $booking->update([
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => max(0, $subtotal + $tax - $discount),
            ]);

            AuditLogger::critical($booking, 'booking.modified', [
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
            ]);

            return $booking->refresh();
        });
    }

    /* ------------------------------------------------------------------ */

    private function assertValidWindow(Carbon $checkIn, Carbon $checkOut): void
    {
        if ($checkOut->lte($checkIn)) {
            throw new DomainValidationException('check_out must be after check_in', 'INVALID_WINDOW');
        }
    }

    /**
     * Guests may only be checked in on or after the reservation's start date,
     * evaluated in the hotel's timezone.
     */
    private function assertCheckInIsOpen(Booking $booking): void
    {
        $timezone = $booking->hotel?->timezone ?: config('app.timezone');
        $today = Carbon::now($timezone)->startOfDay();
        $start = Carbon::parse($booking->check_in)->startOfDay();

        if ($today->lt($start)) {
            throw new DomainValidationException(
                sprintf('Check-in is not allowed before the reservation start date (%s).', $start->toDateString()),
                'CHECK_IN_TOO_EARLY'
            );
        }
    }

    private function assertGuestBelongs(Hotel $hotel, int $guestId): void
    {
        if (! Guest::query()->where('hotel_id', $hotel->id)->whereKey($guestId)->exists()) {
            throw new DomainValidationException('Guest does not belong to this hotel', 'GUEST_TENANT_MISMATCH');
        }
    }

    /**
     * Deduplication key that is only meaningful while an allocation is active.
     * NULL for inactive rows, so tombstones never collide on the unique index.
     */
    private function allocationKey(int $roomId, string $checkIn, string $checkOut): string
    {
        return sprintf('%d-%s-%s', $roomId, $checkIn, $checkOut);
    }

    /**
     * Return the guest for a booking: an explicit $guest_id wins; otherwise the
     * inline guest details are used, reusing an existing profile when the email
     * matches a past reservation, and creating a new one otherwise.
     */
    private function resolveGuest(Hotel $hotel, array $payload, ?User $actor): Guest
    {
        if (! empty($payload['guest_id'])) {
            $this->assertGuestBelongs($hotel, (int) $payload['guest_id']);

            return Guest::query()->whereKey((int) $payload['guest_id'])->first();
        }

        $data = $payload['guest'] ?? [];

        $email = ! empty($data['email'])
            ? mb_strtolower(trim((string) $data['email']))
            : null;

        if ($email) {
            $existing = Guest::query()
                ->where('hotel_id', $hotel->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Guest::create([
            'hotel_id' => $hotel->id,
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);
    }

    private function lockRooms(array $roomIds): void
    {
        if (! empty($roomIds)) {
            Room::query()->whereIn('id', $roomIds)->lockForUpdate()->get();
        }
    }

    /**
     * Rooms must belong to the hotel being booked. Runs OUTSIDE the tenant
     * global scope, otherwise a foreign room would 404 before this check.
     */
    private function assertRoomsBelong(array $rooms, int $hotelId): void
    {
        $ids = array_map(
            fn ($r) => is_array($r) ? (int) $r['room_id'] : (int) $r,
            $rooms
        );
        $ids = array_values(array_unique($ids));

        $found = Room::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('hotel_id', $hotelId)
            ->count();

        if ($found !== count($ids)) {
            throw new DomainValidationException('Room does not belong to this hotel', 'ROOM_TENANT_MISMATCH');
        }
    }

    /**
     * @param array<int, array{room_id: int, rate_plan_id?: int}|int> $rooms
     */
    private function assertRoomsFree(array $rooms, int $hotelId, Carbon $checkIn, Carbon $checkOut, ?int $exclude = null): void
    {
        $roomIds = array_map(
            fn ($r) => is_array($r) ? (int) $r['room_id'] : (int) $r,
            $rooms
        );

        foreach ($roomIds as $roomId) {
            $exists = \DB::table('booking_rooms')
                ->where('hotel_id', $hotelId)
                ->where('active', true)
                ->where('room_id', $roomId)
                ->whereDate('check_in', '<', $checkOut->toDateString())
                ->whereDate('check_out', '>', $checkIn->toDateString())
                ->when($exclude, fn ($q) => $q->where('booking_id', '!=', $exclude))
                ->exists();

            if ($exists) {
                throw new BookingConflictException(
                    "Room #{$roomId} is not free for part of the requested window.",
                    'ROOM_CONFLICT'
                );
            }
        }

        // Cross-check against identical windows on OTHER rooms of a different
        // public availability snapshot is left out: room-level check is exact.
        $this->availability->availableRooms($hotelId, $checkIn->toDateString(), $checkOut->toDateString());
    }

    private function resolvePlan(Room $room, ?int $planId): \App\Models\RatePlan
    {
        $plan = $planId
            ? \App\Models\RatePlan::query()->whereKey($planId)->first()
            : \App\Models\RatePlan::query()->where('room_type_id', $room->room_type_id)->where('is_active', true)->first();

        if (! $plan || ! $plan->is_active) {
            throw new DomainValidationException('No active rate plan for this room', 'NO_RATE_PLAN');
        }

        return $plan;
    }

    private function taxCents(Hotel $hotel, int $subtotal, array $planIds): int
    {
        // If any line uses a tax-included plan, we keep tax simple: apply the
        // hotel default tax only to the untaxed portion. MVP keeps it strict:
        // all-or-nothing per hotel.
        return (int) round($subtotal * ($hotel->tax_rate / 100));
    }

    private function assertStatus(Booking $booking, array $allowed): void
    {
        if (! in_array($booking->status, $allowed, true)) {
            throw new DomainValidationException(
                sprintf('Current status "%s" does not allow this action.', $booking->status),
                'BOOKING_STATUS_TRANSITION'
            );
        }
    }

    private function isOverbookingIntegrityError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'booking_rooms_room_window_active_unique')
            || str_contains($e->getMessage(), 'booking_rooms_active_dedup_key_unique')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}