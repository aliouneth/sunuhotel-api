<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class BookingAvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability)
    {
    }

    /**
     * Free rooms for a given window. Used by the booking form + calendar.
     */
    public function check(Request $request)
    {
        $this->authorize('bookings.view');

        $validated = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'room_type_id' => ['nullable', 'exists:room_types,id'],
            'exclude_booking_id' => ['nullable', 'exists:bookings,id'],
        ]);

        $rooms = $this->availability->availableRooms(
            hotelId: $request->user()->hotel_id,
            checkIn: $validated['check_in'],
            checkOut: $validated['check_out'],
            roomTypeIds: $request->filled('room_type_id') ? [$validated['room_type_id']] : null,
            excludeBookingId: $validated['exclude_booking_id'] ?? null,
        )->load(['roomType:id,name']);

        // Effective nightly rate used in the booking quotation: the room's own
        // daily rate wins, otherwise the active rate plan's base rate.
        $planById = RatePlan::query()
            ->whereIn('room_type_id', $rooms->pluck('room_type_id')->unique()->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('room_type_id');

        $rooms = $rooms->map(function ($room) use ($planById) {
            $rate = $room->daily_rate_cents ?? $planById->get($room->room_type_id)?->base_rate_cents ?? 0;
            $room->setAttribute('rate_cents', (int) $rate);

            return $room;
        })->values();

        return response()->json([
            'data' => [
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'count' => $rooms->count(),
                'rooms' => $rooms->load(['roomType:id,name'])->values(),
            ],
        ]);
    }

    /**
     * Per-day availability count for the calendar heatmap.
     */
    public function calendar(Request $request)
    {
        $this->authorize('bookings.view');

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'room_type_id' => ['nullable', 'exists:room_types,id'],
        ]);

        return response()->json([
            'data' => $this->availability->dailyAvailability(
                hotelId: $request->user()->hotel_id,
                from: $validated['from'],
                to: $validated['to'],
                roomTypeIds: $request->filled('room_type_id') ? [$validated['room_type_id']] : null,
            ),
        ]);
    }
}