<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MonthlyInvoice;
use App\Services\BillingService;
use Illuminate\Http\Request;

/**
 * Hotel-side billing view. Lets members see their hotel's monthly bills
 * (amounts due to the platform) and the current subscription plan. Payment
 * collection (wallet credit, mark-paid) lives on the platform side only.
 */
class HotelBillingController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('payments.view');

        $hotel = $request->user()->hotel;
        $subscription = $hotel->activeSubscription();

        $invoices = MonthlyInvoice::query()
            ->where('hotel_id', $hotel->id)
            ->with('plan')
            ->orderByDesc('billing_month')
            ->paginate(50);

        return response()->json([
            'data' => [
                'currency' => $hotel->currency ?: BillingService::CURRENCY,
                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'plan' => $subscription->plan?->only(['id', 'name', 'monthly_rate_cents', 'currency']),
                ] : null,
                'invoices' => collect($invoices->items())->map(fn (MonthlyInvoice $i) => [
                    'id' => $i->id,
                    'billing_month' => $i->billing_month,
                    'amount_cents' => $i->amount_cents,
                    'paid_cents' => $i->paid_cents,
                    'currency' => $i->currency,
                    'status' => $i->status,
                    'payment_method' => $i->payment_method,
                    'paid_at' => $i->paid_at,
                    'plan' => $i->plan?->only(['id', 'name']),
                ])->values(),
                'total' => $invoices->total(),
            ],
        ]);
    }
}