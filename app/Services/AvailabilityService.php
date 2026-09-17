<?php

namespace App\Services;

use App\Models\BookingRoom;
use App\Models\Room;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Room availability engine.
 *
 * An active BookingRoom allocation uses a room for [check_in, check_out).
 * A candidate block [in, out) is free when no active allocation satisfies
 *   allocation.check_in < out AND allocation.check_out > in
 * (standard interval-overlap test, inclusive of check-in/out boundaries).
 */
final class AvailabilityService
{
    /**
     * Rooms free for the whole window, filtered optionally by room type ids.
     * A booking being edited can pass its id so its own allocations are ignored.
     */
    public function availableRooms(
        int $hotelId,
        string $checkIn,
        string $checkOut,
        ?array $roomTypeIds = null,
        ?int $excludeBookingId = null,
        bool $includeOutOfService = false,
    ): Collection {
        $in = Carbon::parse($checkIn);
        $out = Carbon::parse($checkOut);

        if ($out->lte($in)) {
            return collect();
        }

        // Heat: grab every active allocation overlapping the window (bounded by
        // the two date columns -> index friendly).
        $allocations = BookingRoom::query()
            ->where('hotel_id', $hotelId)
            ->where('active', true)
            ->whereDate('check_in', '<', $out->toDateString())
            ->whereDate('check_out', '>', $in->toDateString())
            ->when($excludeBookingId, fn ($q) => $q->where('booking_id', '!=', $excludeBookingId))
            ->pluck('room_id');

        $busy = $allocations->unique();

        $rooms = Room::query()
            ->where('hotel_id', $hotelId)
            ->when($roomTypeIds, fn ($q) => $q->whereIn('room_type_id', $roomTypeIds))
            ->when(! $includeOutOfService, fn ($q) => $q->whereIn('status', ['available', 'dirty']))
            ->get();

        return $rooms->reject(fn (Room $room) => $busy->contains($room->id))->values();
    }

    /**
     * Availability calendar per day for a range: map day => count available.
     */
    public function dailyAvailability(
        int $hotelId,
        string $from,
        string $to,
        ?array $roomTypeIds = null,
    ): Collection {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        $total = Room::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', ['available', 'dirty'])
            ->when($roomTypeIds, fn ($q) => $q->whereIn('room_type_id', $roomTypeIds))
            ->count();

        $occupiedPerDay = BookingRoom::query()
            ->where('hotel_id', $hotelId)
            ->where('active', true)
            ->whereDate('check_in', '<', $to->toDateString())
            ->whereDate('check_out', '>', $from->toDateString())
            ->when($roomTypeIds, fn ($q) => $q->whereRelation('room', 'room_type_id', $roomTypeIds))
            ->get()
            ->flatMap(fn (BookingRoom $line) => $this->days($line->check_in, $line->check_out))
            ->countBy();

        $result = collect();
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $day = $cursor->toDateString();
            $result->push([
                'date' => $day,
                'total' => $total,
                'occupied' => $occupiedPerDay[$day] ?? 0,
                'available' => max(0, $total - ($occupiedPerDay[$day] ?? 0)),
            ]);
            $cursor->addDay();
        }

        return $result;
    }

    private function days(Carbon $in, Carbon $out): array
    {
        $days = [];
        $cursor = Carbon::parse($in->toDateString());

        while ($cursor->lt($out)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $days;
    }
}