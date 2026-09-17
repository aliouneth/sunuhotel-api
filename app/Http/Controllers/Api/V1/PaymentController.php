<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Booking-level payments ledger.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function index(Request $request, \App\Models\Booking $booking)
    {
        $this->authorize('payments.view');

        return response()->json([
            'data' => $booking->payments()->with('receiver')->latest()->get(),
        ]);
    }

    public function store(Request $request, \App\Models\Booking $booking)
    {
        $this->authorize('payments.create');

        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,card,bank_transfer,mobile_money'],
            'status' => ['sometimes', 'in:pending,completed,failed'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->payments->record($booking, $request->user(), $validated);

        return response()->json(['data' => $payment], 201);
    }

    public function refund(Request $request, Payment $payment)
    {
        $this->authorize('payments.manage');

        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->payments->refund($payment, $validated['reference'] ?? null, $validated['notes'] ?? null);

        return response()->json(['data' => $payment->fresh(['booking'])]);
    }
}