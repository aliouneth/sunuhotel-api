<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('rooms.view');

        $rooms = Room::query()
            ->with(['roomType:id,name,base_rate_cents', 'amenities:id,name,icon'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('room_type_id'), fn ($q) => $q->where('room_type_id', $request->integer('room_type_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('room_number', 'like', '%'.$request->string('search').'%'))
            ->orderBy('floor')
            ->orderBy('room_number')
            ->paginate(50);

        return response()->json(['data' => $rooms]);
    }

    public function store(Request $request)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'room_number' => ['required', 'string', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:999'],
            'room_type_id' => ['required', 'exists:room_types,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'daily_rate_cents' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:available,occupied,dirty,out_of_order,maintenance'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'keycard_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ]);

        $hotel = $request->user()->hotel;
        $hotelId = $hotel->id;

        $room = \DB::transaction(function () use ($validated, $hotel, $hotelId) {
            $roomType = $hotel->roomTypes()->findOrFail($validated['room_type_id']);

            $room = Room::create([
                'hotel_id' => $hotelId,
                'room_number' => $validated['room_number'],
                'floor' => $validated['floor'] ?? 0,
                'room_type_id' => $roomType->id,
                'capacity' => $validated['capacity'] ?? $roomType->base_capacity,
                'daily_rate_cents' => $validated['daily_rate_cents'] ?? null,
                'status' => $validated['status'] ?? 'available',
                'keycard_code' => $validated['keycard_code'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            if (! empty($validated['amenities'])) {
                $room->amenities()->syncWithPivotValues(
                    $validated['amenities'],
                    ['hotel_id' => $hotelId]
                );
            }

            AuditLogger::critical($room, 'room.created');

            return $room;
        });

        return response()->json(['data' => $room->load('amenities')], 201);
    }

    public function show(Request $request, Room $room)
    {
        $this->authorize('rooms.view');

        return response()->json(['data' => $room->load(['roomType', 'amenities'])]);
    }

    public function update(Request $request, Room $room)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'room_number' => ['sometimes', 'string', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:999'],
            'room_type_id' => ['sometimes', 'exists:room_types,id'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'daily_rate_cents' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:available,occupied,dirty,out_of_order,maintenance'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'keycard_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ]);

        \DB::transaction(function () use ($validated, $room, $request) {
            $room->update($validated);

            if (array_key_exists('amenities', $validated)) {
                $room->amenities()->syncWithPivotValues(
                    $validated['amenities'] ?? [],
                    ['hotel_id' => $room->hotel_id]
                );
            }
        });

        AuditLogger::critical($room, 'room.updated');

        return response()->json(['data' => $room->fresh(['roomType', 'amenities'])]);
    }

    /**
     * Fast status toggle without full edit payload.
     */
    public function updateStatus(Request $request, Room $room)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'status' => ['required', 'in:available,occupied,dirty,out_of_order,maintenance'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $room->update($validated);
        AuditLogger::critical($room, 'room.status_changed', ['status' => $validated['status']]);

        return response()->json(['data' => $room->fresh()]);
    }

    public function destroy(Request $request, Room $room)
    {
        $this->authorize('rooms.manage');

        if ($room->bookingRooms()->where('active', true)->exists()) {
            return response()->json([
                'message' => 'Room has active bookings.',
                'error' => 'ROOM_HAS_BOOKINGS',
            ], 422);
        }

        $room->delete();
        AuditLogger::critical($room, 'room.deleted');

        return response()->json(['message' => 'Deleted.']);
    }
}