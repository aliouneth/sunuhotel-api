<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Http\Request;

/**
 * Platform-wide guest administration (outside tenant scope).
 *
 * Platform admins act in no hotel tenant, so they manage guests across every
 * hotel at once: search the full directory, read one guest with their
 * bookings, edit their contact/identity info, force a new password, or send
 * them a self-service password-reset link.
 */
class PlatformGuestController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'hotel' => ['nullable', 'string', 'max:190'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $hotelSlug = trim((string) ($validated['hotel'] ?? ''));

        $query = Guest::query()
            ->with(['hotel:id,name,slug,city,country'])
            ->withCount('bookings')
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            }))
            ->when($hotelSlug !== '', function ($query) use ($hotelSlug) {
                $query->whereHas('hotel', fn ($query) => $query->where('slug', $hotelSlug));
            })
            ->orderByDesc('updated_at');

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function all(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));

        $query = Guest::query()
            ->with(['hotel:id,name,slug,city,country'])
            ->withCount('bookings')
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            }))
            ->orderByDesc('updated_at');

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Guest $guest)
    {
        $guest->loadMissing(['hotel:id,name,slug,city,country', 'bookings']);

        return response()->json(['data' => $guest]);
    }

    public function update(Request $request, Guest $guest)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'max:2'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_blacklisted' => ['nullable', 'boolean'],
        ]);

        $guest->fill($validated);
        $guest->save();

        return response()->json(['data' => $guest]);
    }
}