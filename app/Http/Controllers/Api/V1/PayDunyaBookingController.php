<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingConflictException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingIntent;
use App\Models\Hotel;
use App\Services\BookingService;
use App\Services\PayDunyaService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayDunya payment for a public (guest) reservation.
 *
 * Payment is what guarantees a reservation: no Booking exists until the
 * checkout intent is paid. See PublicHotelController::storeBooking for the
 * intent creation and the create_booking_intents_table migration for the
 * rationale.
 *
 * The IPN here is public and untrusted: it is verified by the SHA-512 hash of
 * the master key AND a server-side re-read of the transaction before the
 * Booking is created. Fulfilment is idempotent per intent.
 */
class PayDunyaBookingController extends Controller
{
    public function __construct(
        private readonly PayDunyaService $paydunya,
        private readonly BookingService $bookings,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Public IPN endpoint: PayDunya POSTs the transaction result here
     * (application/x-www-form-urlencoded, nested under `data`).
     */
    public function ipn(Request $request): JsonResponse
    {
        $data = $request->input('data');

        if (! is_array($data)) {
            return response()->json(['message' => 'Malformed IPN payload.'], 400);
        }

        if (! $this->paydunya->verifyHash($data['hash'] ?? null)) {
            Log::warning('PayDunya booking IPN rejected: bad signature', ['token' => $data['invoice']['token'] ?? null]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $token = $data['invoice']['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Missing invoice token.'], 422);
        }

        // The IPN body is only a hint; re-read the authoritative status so a
        // forged or replayed body cannot create a booking.
        try {
            $confirm = $this->paydunya->confirm($token);
        } catch (\Throwable $e) {
            Log::error('PayDunya booking confirm failed', ['token' => $token, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not verify the payment.'], 502);
        }

        if (! $this->paydunya->verifyHash($confirm['hash'] ?? null)) {
            return response()->json(['message' => 'Invalid confirmation signature.'], 403);
        }

        if (($confirm['status'] ?? null) !== 'completed') {
            return response()->json(['received' => true, 'status' => $confirm['status'] ?? 'unknown']);
        }

        $intent = $this->resolveIntent($confirm, $data, $token);
        if (! $intent) {
            Log::warning('PayDunya booking IPN for an unknown intent', ['token' => $token]);

            return response()->json(['message' => 'Unknown payment.'], 404);
        }

        if ($intent->status === BookingIntent::STATUS_PAID) {
            return response()->json(['received' => true, 'status' => 'already_paid']);
        }

        $expectedMajor = $this->paydunya->toMajorUnits($intent->total_cents);
        $confirmedMajor = $confirm['invoice']['total_amount'] ?? null;

        if ($confirmedMajor !== null && (int) $confirmedMajor < $expectedMajor) {
            Log::warning('PayDunya booking IPN amount mismatch', [
                'intent_id' => $intent->id,
                'expected' => $expectedMajor,
                'received' => $confirmedMajor,
            ]);

            return response()->json(['message' => 'Amount mismatch.'], 409);
        }

        try {
            $booking = $this->fulfil($intent);
        } catch (BookingConflictException $e) {
            // The guest paid but the rooms were taken in the meantime (rooms are
            // not held before payment). Nothing is auto-refunded; flag it loudly
            // for staff to resolve.
            $intent->update(['status' => BookingIntent::STATUS_FAILED]);
            Log::critical('Paid booking intent could not be fulfilled: rooms unavailable', [
                'intent_id' => $intent->id,
                'token' => $token,
                'total_cents' => $intent->total_cents,
            ]);

            return response()->json(['received' => true, 'status' => 'unavailable'], 409);
        }

        return response()->json([
            'received' => true,
            'status' => 'paid',
            'booking_number' => $booking->booking_number,
        ]);
    }

    /**
     * Public status lookup for the return_url screen. Returns only the intent
     * status and (once paid) the booking number — never guest PII.
     */
    public function status(string $token): JsonResponse
    {
        $intent = BookingIntent::query()->withoutGlobalScopes()->where('token', $token)->first();

        if (! $intent) {
            return response()->json(['message' => 'Unknown payment.'], 404);
        }

        return response()->json([
            'data' => [
                'status' => $intent->status,
                'booking_number' => $intent->booking?->booking_number,
                'amount_cents' => $intent->total_cents,
                'currency' => $intent->currency,
            ],
        ]);
    }

    /**
     * Create the confirmed Booking + its payment ledger entry, then link the
     * intent. Idempotent: a second call for an already-paid intent is a no-op.
     */
    private function fulfil(BookingIntent $intent): Booking
    {
        return DB::transaction(function () use ($intent) {
            $locked = BookingIntent::query()
                ->withoutGlobalScopes()
                ->whereKey($intent->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === BookingIntent::STATUS_PAID && $locked->booking_id) {
                return Booking::query()->withoutGlobalScopes()->whereKey($locked->booking_id)->firstOrFail();
            }

            $hotel = Hotel::query()->withoutGlobalScopes()->findOrFail($locked->hotel_id);

            $booking = $this->bookings->create($hotel, null, [
                'guest' => [
                    'first_name' => $locked->guest_first_name,
                    'last_name' => $locked->guest_last_name,
                    'email' => $locked->guest_email,
                    'phone' => $locked->guest_phone,
                ],
                'check_in' => $locked->check_in->toDateString(),
                'check_out' => $locked->check_out->toDateString(),
                'rooms' => array_map(fn ($id) => ['room_id' => (int) $id], $locked->rooms),
                'adults' => $locked->adults,
                'children' => $locked->children,
                'source' => 'website',
                'status' => 'confirmed',
                'notes' => $locked->notes,
            ]);

            $this->payments->recordGateway($booking, [
                'amount_cents' => $booking->total_cents,
                'method' => 'mobile_money',
                'status' => 'completed',
                'type' => 'payment',
                'reference' => $locked->token,
                'notes' => 'PayDunya online payment',
                'gateway' => 'paydunya',
            ]);

            $locked->update([
                'status' => BookingIntent::STATUS_PAID,
                'booking_id' => $booking->id,
                'paid_at' => now(),
            ]);

            return $booking;
        });
    }

    /**
     * Match an IPN/confirm payload back to its intent: prefer the id stashed in
     * custom_data, fall back to the stored gateway token.
     */
    private function resolveIntent(array $confirm, array $data, string $token): ?BookingIntent
    {
        $intentId = $confirm['custom_data']['intent_id']
            ?? $data['custom_data']['intent_id']
            ?? null;

        if ($intentId) {
            $intent = BookingIntent::query()->withoutGlobalScopes()->find($intentId);
            if ($intent) {
                return $intent;
            }
        }

        return BookingIntent::query()->withoutGlobalScopes()->where('token', $token)->first();
    }
}
