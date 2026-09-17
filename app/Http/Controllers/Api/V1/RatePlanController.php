<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Services\AuditLogger;
use App\Services\RateService;
use Illuminate\Http\Request;

class RatePlanController extends Controller
{
    public function __construct(private readonly RateService $rates)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('rooms.view');

        $plans = RatePlan::query()
            ->with('roomType:id,name')
            ->orderBy('name')
            ->paginate(50);

        return response()->json(['data' => $plans]);
    }

    public function store(Request $request)
    {
        $this->authorize('rate-plans.manage');

        $validated = $request->validate([
            'room_type_id' => ['required', 'exists:room_types,id'],
            'name' => ['required', 'string', 'max:80'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'base_rate_cents' => ['required', 'integer', 'min:0'],
            'basis' => ['sometimes', 'in:daily,weekly,seasonal'],
            'week_multiplier' => ['nullable', 'numeric', 'between:0,5'],
            'days_rules' => ['nullable', 'array'],
            'season_rules' => ['nullable', 'array'],
            'tax_included' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $hotelId = $request->user()->hotel_id;
        $request->user()->hotel->roomTypes()->findOrFail($validated['room_type_id']);

        $plan = RatePlan::create(array_merge($validated, [
            'hotel_id' => $hotelId,
            'currency' => $validated['currency'] ?? $request->user()->hotel->currency,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        AuditLogger::critical($plan, 'rate_plan.created');

        return response()->json(['data' => $plan->load('roomType')], 201);
    }

    public function show(Request $request, RatePlan $ratePlan)
    {
        $this->authorize('rooms.view');

        return response()->json(['data' => $ratePlan->load('roomType', 'overrides')]);
    }

    /**
     * Price a hypothetical stay: returns per-night breakdown + totals.
     */
    public function quote(Request $request, RatePlan $ratePlan)
    {
        $this->authorize('rooms.view');

        $validated = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'nights' => ['nullable', 'integer', 'min:1'],
        ]);

        $nights = $validated['nights'] ?? (int) \Illuminate\Support\Carbon::parse($validated['check_in'])
            ->diffInDays(\Illuminate\Support\Carbon::parse($validated['check_out']));

        $hotel = $request->user()->hotel;
        $tax = (int) round($this->rates->stayTotalCents($ratePlan, $validated['check_in'], $validated['check_out']) * ($hotel->tax_rate / 100));

        return response()->json([
            'data' => [
                'rate_plan' => $ratePlan->name,
                'base_rate_cents' => $ratePlan->base_rate_cents,
                'nights' => $nights,
                'nights_breakdown' => $this->rates->ratesForStay(
                    $ratePlan,
                    $validated['check_in'],
                    $validated['check_out']
                )->values(),
                'subtotal_cents' => $this->rates->stayTotalCents($ratePlan, $validated['check_in'], $validated['check_out']),
                'tax_cents' => $tax,
                'total_cents' => $this->rates->stayTotalCents($ratePlan, $validated['check_in'], $validated['check_out']) + $tax,
            ],
        ]);
    }

    public function update(Request $request, RatePlan $ratePlan)
    {
        $this->authorize('rate-plans.manage');

        $validated = $request->validate([
            'room_type_id' => ['sometimes', 'exists:room_types,id'],
            'name' => ['sometimes', 'string', 'max:80'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'base_rate_cents' => ['sometimes', 'integer', 'min:0'],
            'basis' => ['sometimes', 'in:daily,weekly,seasonal'],
            'week_multiplier' => ['nullable', 'numeric', 'between:0,5'],
            'days_rules' => ['nullable', 'array'],
            'season_rules' => ['nullable', 'array'],
            'tax_included' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $ratePlan->update($validated);
        AuditLogger::critical($ratePlan, 'rate_plan.updated');

        return response()->json(['data' => $ratePlan->fresh()]);
    }

    public function destroy(Request $request, RatePlan $ratePlan)
    {
        $this->authorize('rate-plans.manage');

        if ($ratePlan->overrides()->exists() || $ratePlan->bookingRooms()->exists()) {
            return response()->json([
                'message' => 'Rate plan is in use; deactivate it instead.',
                'error' => 'RATE_PLAN_IN_USE',
            ], 422);
        }

        $ratePlan->delete();
        AuditLogger::critical($ratePlan, 'rate_plan.deleted');

        return response()->json(['message' => 'Deleted.']);
    }
}