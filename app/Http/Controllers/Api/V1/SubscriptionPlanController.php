<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

/**
 * Platform-scoped CRUD for subscription plans (name, max rooms, monthly rate).
 * All actions run under the platform.admin middleware group; no hotel context
 * is involved since plans are platform-owned.
 */
class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SubscriptionPlan::orderByRaw('is_active desc, monthly_rate_cents asc')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
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

    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
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

    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json(['data' => null], 204);
    }
}
