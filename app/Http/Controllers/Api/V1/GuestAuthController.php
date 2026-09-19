<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Guest self-service auth. Guests are created per hotel at booking time with an
 * optional password (the "create an account with this password on your first
 * booking" flow). A guest then logs in with email + password from any hotel's
 * public page to see all of their previous reservations across hotels.
 *
 * This controller deliberately runs OUTSIDE the tenant middleware: guests span
 * multiple hotels (the same person can hold guest records at several hotels).
 */
class GuestAuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        // A guest can hold multiple records across hotels. Credentials match
        // if the email + password belong to ANY guest record.
        $guests = Guest::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNotNull('password')
            ->get();

        $matched = $guests->first(
            fn (Guest $g) => Hash::check($validated['password'], $g->password),
        );

        if (! $matched) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match any account.'],
            ]);
        }

        $token = $matched->createToken('guest-auth')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'guest' => [
                    'id' => $matched->id,
                    'first_name' => $matched->first_name,
                    'last_name' => $matched->last_name,
                    'email' => $matched->email,
                ],
            ],
        ]);
    }

    /**
     * Reservations for the authenticated guest across all of their hotel
     * records, newest reservation first.
     *
     * The guest portal is per-hotel (the "Mes réservations" page is reached
     * from a specific hotel's public site). When a `hotel` (slug) query param
     * is supplied, only the current hotel's bookings are returned so that two
     * different hotels' guest pages never surface the same union list.
     */
    public function myBookings(Request $request)
    {
        $guest = $request->user();
        abort_if(! $guest instanceof Guest, 403, 'Guests only.');

        $email = mb_strtolower(trim((string) $guest->email));
        $guestIds = Guest::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->pluck('id')
            ->all();

        $bookings = Booking::query()
            ->with(['hotel:id,name,slug,city,currency', 'rooms.room'])
            ->when($hotelId = $this->hotelIdForSlug($request->query('hotel')), function ($q, $hotelId) {
                return $q->where('hotel_id', $hotelId);
            })
            ->whereIn('guest_id', $guestIds)
            ->orderByDesc('check_in')
            ->limit(100)
            ->get()
            ->map(fn (Booking $b) => $this->present($b));

        return response()->json(['data' => $bookings]);
    }

    /**
     * Resolve a hotel by slug outside the tenant middleware (the guest portal
     * runs outside the tenant scope). Returns null when no slug is given so the
     * union list is preserved for callers that do not scope by hotel.
     */
    private function hotelIdForSlug(?string $slug): ?int
    {
        if (! $slug) {
            return null;
        }

        $hotel = Hotel::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->first();

        return $hotel?->id;
    }

    private function present(Booking $booking): array
    {
        return [
            'booking_number' => $booking->booking_number,
            'status' => $booking->status,
            'check_in' => $booking->check_in?->toDateString(),
            'check_out' => $booking->check_out?->toDateString(),
            'nights' => $booking->nights,
            'adults' => $booking->adults,
            'children' => $booking->children,
            'total_cents' => $booking->total_cents,
            'paid_cents' => $booking->paid_cents,
            'currency' => $booking->hotel?->currency,
            'hotel' => $booking->hotel
                ? [
                    'slug' => $booking->hotel->slug,
                    'name' => $booking->hotel->name,
                    'city' => $booking->hotel->city,
                ]
                : null,
            'rooms' => $booking->rooms->map(fn ($line) => [
                'room_number' => $line->room?->room_number,
                'room_type' => $line->room?->roomType?->name,
            ])->values()->all(),
        ];
    }
}
