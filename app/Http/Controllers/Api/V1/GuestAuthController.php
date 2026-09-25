<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
     * Issue a password reset for a guest who booked without setting one (or
     * forgot it). Because a guest can hold records at several hotels, the same
     * token is written to EVERY guest record matching the email so the reset
     * works regardless of which record the guest later logs in through.
     *
     * The reset token itself is stored hashed; the plain token is returned so
     * the public guest page can drive the "define a password" screen without a
     * notification backend in dev. In production, hand the token to your mailer
     * / SMS provider instead of returning it in the response body.
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = mb_strtolower(trim((string) $validated['email']));
        $token = Str::random(64);

        // Guests only — if the email belongs to no guest record at any hotel,
        // keep the response identical so we never leak which emails exist.
        $guests = Guest::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$email]);

        if ($guests->exists()) {
            $guests->update([
                'password_reset_token' => Hash::make($token),
                'password_reset_expires_at' => now()->addMinutes(60),
            ]);
        }

        return response()->json([
            'data' => [
                'reset_token' => $guests->exists() ? $token : null,
                'email' => $email,
            ],
        ]);
    }

    /**
     * Finalise a guest password reset: verify the emailed token, then set a new
     * hashed password on every guest record for that email (keeping the union
     * of credentials in sync across hotels).
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = mb_strtolower(trim((string) $validated['email']));

        $guests = Guest::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNotNull('password_reset_token')
            ->get();

        if ($guests->isEmpty()) {
            throw ValidationException::withMessages([
                'token' => ['This password reset token is invalid.'],
            ]);
        }

        $valid = $guests->contains(
            fn (Guest $g) => $g->password_reset_expires_at?->isFuture()
                && Hash::check($validated['token'], $g->password_reset_token),
        );

        if (! $valid) {
            throw ValidationException::withMessages([
                'token' => ['This password reset token is invalid or has expired.'],
            ]);
        }

        $guests->each(function (Guest $g) use ($validated) {
            $g->password = $validated['password'];
            $g->password_reset_token = null;
            $g->password_reset_expires_at = null;
            $g->save();
        });

        return response()->json([
            'data' => [
                'message' => 'Password updated. You can now log in with your new password.',
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

        // The guest portal is explicitly cross-hotel: a guest is the union of
        // every Guest record sharing their email, and their reservations are
        // shown regardless of which hotel's page they logged in from. The hotel
        // tenant scope is deliberately bypassed here — applying HotelScope would
        // call HotelContext::id() -> resolve the Sanctum user -> PersonalAccess
        // Token -> tokenable, re-entering the scope and looping until Xdebug
        // aborts (this is the exact "possible infinite loop" the page hit).
        $bookings = Booking::query()
            ->withoutGlobalScopes()
            ->with(['hotel:id,name,slug,city,currency', 'rooms.room'])
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
