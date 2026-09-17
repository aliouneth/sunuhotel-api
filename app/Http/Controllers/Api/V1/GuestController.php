<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('guests.view');

        $guests = Guest::query()
            ->withCount('bookings')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search');
                $q->where(function ($q) use ($term) {
                    $q->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->orderBy('last_name')
            ->paginate(50);

        return response()->json(['data' => $guests]);
    }

    /**
     * Find the most recent guest profile for an email address, so the booking
     * desk can prefill returning guests' details.
     */
    public function lookup(Request $request)
    {
        $this->authorize('guests.view');

        $email = $request->filled('email')
            ? mb_strtolower(trim($request->string('email')))
            : null;

        $guest = $email
            ? Guest::query()
                ->where('hotel_id', $request->user()->hotel_id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->latest('id')
                ->first()
            : null;

        return response()->json(['data' => $guest]);
    }

    public function store(Request $request)
    {
        $this->authorize('guests.manage');

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'size:3'],
            'id_type' => ['nullable', 'in:passport,national_id,driver_license,other'],
            'id_number' => ['nullable', 'string', 'max:60'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'size:3'],
            'preferences' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
            'is_blacklisted' => ['nullable', 'boolean'],
        ]);

        $guest = Guest::create(array_merge($validated, [
            'hotel_id' => $request->user()->hotel_id,
            'created_by' => $request->user()->id,
        ]));

        AuditLogger::critical($guest, 'guest.created');

        return response()->json(['data' => $guest], 201);
    }

    public function show(Request $request, Guest $guest)
    {
        $this->authorize('guests.view');

        return response()->json(['data' => $guest]);
    }

    public function history(Request $request, Guest $guest)
    {
        $this->authorize('guests.view');

        return response()->json([
            'data' => $guest->bookings()
                ->with(['rooms.room'])
                ->latest('check_in')
                ->get()
                ->map(fn ($b) => $b->only([
                    'id', 'booking_number', 'status', 'check_in', 'check_out',
                    'total_cents', 'paid_cents',
                ]) + ['rooms' => $b->rooms->map(fn ($r) => $r->room->room_number)->implode(', ')]),
        ]);
    }

    public function update(Request $request, Guest $guest)
    {
        $this->authorize('guests.manage');

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'size:3'],
            'id_type' => ['nullable', 'in:passport,national_id,driver_license,other'],
            'id_number' => ['nullable', 'string', 'max:60'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'size:3'],
            'preferences' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
            'is_blacklisted' => ['nullable', 'boolean'],
        ]);

        $guest->update($validated);
        AuditLogger::critical($guest, 'guest.updated');

        return response()->json(['data' => $guest->fresh()]);
    }

    public function destroy(Request $request, Guest $guest)
    {
        $this->authorize('guests.manage');

        $guest->delete();
        AuditLogger::critical($guest, 'guest.deleted');

        return response()->json(['message' => 'Deleted.']);
    }
}