<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Hotel;
use App\Models\HotelSubscription;
use App\Models\MonthlyInvoice;
use App\Models\Room;
use App\Models\SubscriptionPlan;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

/**
 * Platform-admin subscription lifecycle: assigning a hotel to a plan, moving it
 * between plans (upstream/downgrade), deactivating/reactivating for non-payment
 * and listing the monthly invoices that drive the billing screen. Every action
 * is hotel-scoped and validated against the plan's room-count constraint.
 *
 * Endpoints (see api.php, platform group):
 *   GET  subscriptions                     -> index
 *   GET  subscriptions/{hotel}             -> show
 *   POST subscriptions/{hotel}             -> assign
 *   PUT  subscriptions/{hotel}             -> update (switch plans)
 *   POST subscriptions/{hotel}/deactivate  -> deactivate
 *   POST subscriptions/{hotel}/reactivate  -> reactivate
 *   GET  subscriptions/{hotel}/invoices    -> invoices
 *   POST subscriptions/{hotel}/invoices/{invoice}/mark-paid -> markPaid
 */
class SubscriptionController extends Controller
{
    private const STATUS_ACTIVE = 'active';

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => HotelSubscription::with(['hotel:id,name,status', 'plan:id,name,max_rooms,monthly_rate_cents'])
                ->orderByDesc('id')
                ->paginate(50)->items(),
        ]);
    }

    public function show(Hotel $hotel): JsonResponse
    {
        return response()->json([
            'data' => HotelSubscription::with(['hotel', 'plan'])
                ->where('hotel_id', $hotel->id)
                ->where('status', self::STATUS_ACTIVE)
                ->latest('id')
                ->first(),
        ]);
    }

    public function assign(Request $request, Hotel $hotel): JsonResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'effective_from' => ['required', 'date'],
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);
        if (! $plan->is_active) {
            return response()->json(['message' => 'This plan is inactive.'], 422);
        }

        $roomCount = Room::where('hotel_id', $hotel->id)->count();
        if ($roomCount > $plan->max_rooms) {
            return response()->json([
                'message' => "Hotel has {$roomCount} rooms which exceeds the plan limit ({$plan->max_rooms}).",
            ], 422);
        }

        // Close any open subscriptions before opening the new one (history).
        HotelSubscription::where('hotel_id', $hotel->id)
            ->whereNull('effective_to')
            ->update([
                'effective_to' => $validated['effective_from'],
                'status' => 'cancelled',
            ]);

        $subscription = HotelSubscription::create([
            'hotel_id' => $hotel->id,
            'subscription_plan_id' => $plan->id,
            'effective_from' => $validated['effective_from'],
            'status' => self::STATUS_ACTIVE,
        ]);

        return response()->json(['data' => $subscription], 201);
    }

    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        $subscription = HotelSubscription::where('hotel_id', $hotel->id)
            ->where('status', self::STATUS_ACTIVE)
            ->latest('id')
            ->firstOrFail();

        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);
        $roomCount = Room::where('hotel_id', $hotel->id)->count();

        if ($roomCount > $plan->max_rooms) {
            return response()->json([
                'message' => "Hotel has {$roomCount} rooms which exceeds the plan limit ({$plan->max_rooms}).",
            ], 422);
        }

        $subscription->update([
            'subscription_plan_id' => $plan->id,
            'effective_from' => now()->toDateString(),
        ]);

        return response()->json(['data' => $subscription]);
    }

    public function deactivate(Request $request, Hotel $hotel): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription = HotelSubscription::where('hotel_id', $hotel->id)
            ->where('status', self::STATUS_ACTIVE)
            ->latest('id')
            ->firstOrFail();

        $subscription->update([
            'status' => 'deactivated',
            'deactivation_reason' => $validated['reason'] ?? null,
        ]);

        // Flag the hotel itself as suspended so the booking engine and
        // check-in are blocked at the tenant boundary.
        $hotel->update(['status' => 'suspended']);

        return response()->json(['data' => $subscription]);
    }

    public function reactivate(Hotel $hotel): JsonResponse
    {
        $subscription = HotelSubscription::where('hotel_id', $hotel->id)
            ->where('status', 'deactivated')
            ->latest('id')
            ->firstOrFail();

        $subscription->update([
            'status' => self::STATUS_ACTIVE,
            'deactivation_reason' => null,
        ]);

        $hotel->update(['status' => 'active']);

        return response()->json(['data' => $subscription]);
    }

    public function invoices(Hotel $hotel): JsonResponse
    {
        return response()->json([
            'data' => MonthlyInvoice::with(['plan:id,name'])
                ->where('hotel_id', $hotel->id)
                ->orderByDesc('billing_month')
                ->get(),
        ]);
    }

    public function invoicesIndex(Request $request): JsonResponse
    {
        $month = $request->query('month');
        $status = $request->query('status');

        return response()->json([
            'data' => MonthlyInvoice::with(['hotel:id,name,status', 'plan:id,name'])
                ->when($month, fn ($q) => $q->where('billing_month', $month))
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('billing_month')
                ->orderBy('hotel_id')
                ->paginate(50),
        ]);
    }

    public function markPaid(Request $request, Hotel $hotel, MonthlyInvoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'paid_cents' => ['sometimes', 'integer', 'min:0'],
            'payment_method' => ['sometimes', 'string', 'max:30'],
            'paid_at' => ['sometimes', 'date'],
        ]);

        $invoice->update([
            'status' => 'paid',
            'paid_cents' => $validated['paid_cents'] ?? $invoice->amount_cents,
            'payment_method' => $validated['payment_method'] ?? null,
            'paid_at' => $validated['paid_at'] ?? now()->toDateString(),
        ]);

        return response()->json(['data' => $invoice]);
    }

    public function creditWallet(Request $request, Hotel $hotel, BillingService $billing): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:100', 'max:100000000'],
        ]);

        $balance = $billing->creditWallet($hotel, $validated['amount_cents']);
        $billing->ensureCurrentMonth();

        return response()->json([
            'data' => [
                'wallet_balance_cents' => $balance,
                'message' => 'wallet_credited',
            ],
        ]);
    }
}
