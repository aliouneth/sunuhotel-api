<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use Illuminate\Http\Request;

class AmenityController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('rooms.view');

        return response()->json([
            'data' => Amenity::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'icon' => ['nullable', 'string', 'max:60'],
        ]);

        $amenity = Amenity::create([
            'hotel_id' => $request->user()->hotel_id,
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
        ]);

        return response()->json(['data' => $amenity], 201);
    }

    public function update(Request $request, Amenity $amenity)
    {
        $this->authorize('rooms.manage');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'icon' => ['nullable', 'string', 'max:60'],
        ]);

        $amenity->update($validated);

        return response()->json(['data' => $amenity->fresh()]);
    }

    public function destroy(Request $request, Amenity $amenity)
    {
        $this->authorize('rooms.manage');

        $amenity->rooms()->detach();
        $amenity->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}