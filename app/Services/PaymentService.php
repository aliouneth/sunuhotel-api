<?php

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payment ledger (no gateway). Recording a completed payment immediately
 * boosts the booking's paid_cents; refunds decrement it. Recomputes the
 * booking balance in the same transaction for consistency.
 */
final class PaymentService
{
    public function record(Booking $booking, User $actor, array $payload): Payment
    {
        $amount = (int) $payload['amount_cents'];
        if ($amount <= 0) {
            throw new ValidationException('Payment amount must be positive.', 'INVALID_AMOUNT');
        }

        return DB::transaction(function () use ($booking, $actor, $payload, $amount) {
            $payment = Payment::create([
                'hotel_id' => $booking->hotel_id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guest_id,
                'amount_cents' => $amount,
                'method' => $payload['method'] ?? 'cash',
                'type' => $payload['type'] ?? 'payment',
                'status' => $payload['status'] ?? 'completed',
                'reference' => $payload['reference'] ?? null,
                'paid_at' => ($payload['status'] ?? 'completed') === 'completed' ? now() : null,
                'received_by' => $actor->id,
                'notes' => $payload['notes'] ?? null,
            ]);

            if ($payment->status === 'completed') {
                $this->recomputePaid($booking);
            }

            AuditLogger::critical($payment, 'payment.recorded', [
                'booking' => $booking->booking_number,
                'amount_cents' => $amount,
            ]);

            return $payment;
        });
    }

    public function refund(Payment $payment, string $reference = null, string $notes = null): Payment
    {
        return DB::transaction(function () use ($payment, $reference, $notes) {
            $payment->update([
                'status' => 'refunded',
                'reference' => $reference ?? $payment->reference,
                'notes' => $notes ?? $payment->notes,
            ]);

            $this->recomputePaid($payment->booking);
            AuditLogger::critical($payment, 'payment.refunded');

            return $payment->refresh();
        });
    }

    private function recomputePaid(Booking $booking): void
    {
        $paid = (int) $booking->payments()
            ->where('status', 'completed')
            ->where('type', '!=', 'refund')
            ->sum('amount_cents');

        // Refunded amounts reduce the collected total.
        $refunded = (int) $booking->payments()
            ->where('status', 'refunded')
            ->sum('amount_cents');

        $booking->update(['paid_cents' => max(0, $paid - $refunded)]);
    }
}