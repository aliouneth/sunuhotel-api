<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomTypeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('rooms.view');

        $roomTypes = RoomType::query()
            ->withCount('rooms')
            ->orderBy('name')
            ->paginate(50);

        return response()->json(['data' => $roomTypes]);
    }

    public function store(Request $request)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'base_capacity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'base_rate_cents' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $roomType = RoomType::create([
            'hotel_id' => $request->user()->hotel_id,
            'slug' => Str::slug($validated['name']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'base_capacity' => $validated['base_capacity'] ?? 2,
            'max_capacity' => $validated['max_capacity'] ?? (int) ($validated['base_capacity'] ?? 2) + 2,
            'base_rate_cents' => $validated['base_rate_cents'] ?? 0,
            'features' => $validated['features'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogger::critical($roomType, 'room_type.created');

        return response()->json(['data' => $roomType], 201);
    }

    public function show(Request $request, RoomType $roomType)
    {
        $this->authorize('rooms.view');

        $roomType->loadCount('rooms');

        return response()->json(['data' => $roomType]);
    }

    public function update(Request $request, RoomType $roomType)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'base_capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'base_rate_cents' => ['sometimes', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $roomType->update($validated);
        AuditLogger::critical($roomType, 'room_type.updated');

        return response()->json(['data' => $roomType->fresh()]);
    }

    public function destroy(Request $request, RoomType $roomType)
    {
        $this->authorize('rooms.manage');

        if ($roomType->rooms()->exists()) {
            return response()->json([
                'message' => 'Room type has rooms; reassign them first.',
                'error' => 'ROOM_TYPE_IN_USE',
            ], 422);
        }

        $roomType->delete();
        AuditLogger::critical($roomType, 'room_type.deleted');

        return response()->json(['message' => 'Deleted.']);
    }
}