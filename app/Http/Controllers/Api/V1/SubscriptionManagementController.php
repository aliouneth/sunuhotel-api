<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\HotelSubscription;
use App\Models\MonthlyInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse as Response;

/**
 * Platform-admin subscription & billing management. Hotel context is NOT the
 * tenant scope here — the platform admin manages many hotels, so every query is
 * explicitly hotel-scoped and every action authorized via the platform.admin
 * guard. Plan CRUD is platform-global.
 */
class SubscriptionManagementController extends Controller
{
    public function plans(): Response
    {
        return response()->json(['data' => SubscriptionPlan::query()->orderBy('monthly_rate_cents')->get()]);
    }

    public function storePlan(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'max_rooms' => ['required', 'integer', 'min:1'],
            'monthly_rate_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan = SubscriptionPlan::create($validated);

        return response()->json(['data' => $plan], 201);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): Response
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'max_rooms' => ['sometimes', 'integer', 'min:1'],
            'monthly_rate_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan->update($validated);

        return response()->json(['data' => $plan]);
    }

    public function subscriptions(Hotel $hotel): Response
    {
        return response()->json([
            'data' => HotelSubscription::query()
                ->with('plan')
                ->where('hotel_id', $hotel->id)
                ->orderByDesc('effective_from')
                ->get(),
        ]);
    }

    public function assign(Request $request, Hotel $hotel): Response
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'exists:subscription_plans,id'],
            'effective_from' => ['required', 'date'],
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);

        // Room-count guard: the hotel must fit the plan today.
        $roomCount = \App\Models\Room::where('hotel_id', $hotel->id)->count();
        if ($roomCount > $plan->max_rooms) {
            return response()->json([
                'message' => 'Hotel has '.$roomCount.' rooms, exceeds plan max of '.$plan->max_rooms,
                'errors' => ['room_count' => ['must not exceed plan max_rooms']],
            ], 422);
        }

        // Close any active row before opening the new one.
        HotelSubscription::where('hotel_id', $hotel->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => $validated['effective_from'], 'status' => HotelSubscription::STATUS_CANCELLED]);

        $sub = HotelSubscription::create([
            'hotel_id' => $hotel->id,
            'subscription_plan_id' => $plan->id,
            'effective_from' => $validated['effective_from'],
            'status' => HotelSubscription::STATUS_ACTIVE,
        ]);

        return response()->json(['data' => $sub], 201);
    }

    public function deactivate(Request $request, Hotel $hotel): Response
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        HotelSubscription::where('hotel_id', $hotel->id)
            ->whereNull('effective_to')
            ->update([
                'status' => HotelSubscription::STATUS_DEACTIVATED,
                'deactivation_reason' => $validated['reason'] ?? null,
            ]);

        return response()->json(['data' => ['deactivated' => true]]);
    }

    public function invoices(Hotel $hotel): Response
    {
        return response()->json([
            'data' => MonthlyInvoice::query()
                ->with(['subscription.plan'])
                ->where('hotel_id', $hotel->id)
                ->orderByDesc('billing_month')
                ->get(),
        ]);
    }

    public function markPaid(Request $request, MonthlyInvoice $invoice): Response
    {
        $validated = $request->validate([
            'paid_cents' => ['sometimes', 'integer', 'min:0'],
            'payment_method' => ['sometimes', 'string', 'max:30'],
            'paid_on' => ['sometimes', 'date'],
        ]);

        $invoice->update([
            'status' => 'paid',
            'paid_cents' => $validated['paid_cents'] ?? $invoice->amount_cents,
            'payment_method' => $validated['payment_method'] ?? null,
            'paid_at' => $validated['paid_on'] ?? now()->toDateString(),
        ]);

        return response()->json(['data' => $invoice]);
    }
}
