<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Hotel;
use App\Models\Promotion;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;

/**
 * Platform-scoped CRUD for hotel promotions. Creating an active promotion bills
 * the hotel: the promo fee is appended to the current month's invoice via
 * BillingService::billPromotion — the same engine that settles invoices from
 * the hotel wallet.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly BillingService $billing)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Promotion::with(['hotel:id,name,slug,status,currency', 'roomType:id,name'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json([
            'data' => $promotion->load(['hotel:id,name,status,currency', 'roomType:id,name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePromotion($request);

        $hotel = Hotel::findOrFail($validated['hotel_id']);
        $this->assertRoomTypeBelongsToHotel($validated['room_type_id'], $hotel->id);

        $isCurrentPeriod =
            ($validated['is_active'] ?? true)
            && $validated['starts_on'] <= now()->toDateString()
            && $validated['ends_on'] >= now()->toDateString();

        $promotion = Promotion::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Bill the hotel for the promotion fee (current month invoice).
        if ($isCurrentPeriod && $validated['fee_cents'] > 0) {
            $this->billPromotionFee($hotel, $promotion, (int) $validated['fee_cents']);
        }

        return response()->json(['data' => $promotion->load(['hotel:id,name,status', 'roomType:id,name'])], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $this->validatePromotion($request, $promotion);

        $this->assertRoomTypeBelongsToHotel($validated['room_type_id'], $promotion->hotel_id);

        $wasCurrentPeriod =
            $promotion->is_active
            && $promotion->starts_on->toDateString() <= now()->toDateString()
            && $promotion->ends_on->toDateString() >= now()->toDateString();

        $promotion->update($validated);

        $isCurrentPeriod =
            ($validated['is_active'] ?? $promotion->is_active)
            && $validated['starts_on'] <= now()->toDateString()
            && $validated['ends_on'] >= now()->toDateString();

        // Bill for newly-current promotions; already-billed fees aren't
        // reversed here (a future "unbill" flow could add that).
        if (! $wasCurrentPeriod && $isCurrentPeriod && $validated['fee_cents'] > 0) {
            $this->billPromotionFee($promotion->hotel, $promotion, (int) $validated['fee_cents']);
        }

        return response()->json(['data' => $promotion->load(['hotel:id,name,status', 'roomType:id,name'])]);
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(['data' => null], 204);
    }

    private function billPromotionFee(Hotel $hotel, Promotion $promotion, int $feeCents): void
    {
        $label = $promotion->title ?: 'Room promotion';
        $note = "Promo: {$label} ({$promotion->starts_on->toDateString()} → {$promotion->ends_on->toDateString()})";
        $this->billing->billPromotion($hotel, $feeCents, $note);
    }

    private function validatePromotion(Request $request, ?Promotion $promotion = null): array
    {
        return $request->validate([
            'hotel_id' => ['required', 'integer', 'exists:hotels,id'],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'title' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'original_rate_cents' => ['required', 'integer', 'min:0'],
            'promo_rate_cents' => ['required', 'integer', 'min:0', 'lte:original_rate_cents'],
            'currency' => ['required', 'string', 'size:3'],
            'fee_cents' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertRoomTypeBelongsToHotel(int $roomTypeId, int $hotelId): void
    {
        $exists = \App\Models\RoomType::query()
            ->where('id', $roomTypeId)
            ->where('hotel_id', $hotelId)
            ->exists();

        abort_unless($exists, 422, 'Room type does not belong to this hotel.');
    }
}