<?php

namespace App\Support\Tenancy;

use App\Models\Hotel;

/**
 * Holds the "current tenant" for the lifetime of the request.
 *
 * The value is resolved once by the tenant middleware from the authenticated
 * user's hotel_id (or an explicit X-Hotel header/booking context), then reused
 * by:
 *  - HotelScope (read isolation, adds `where hotel_id = ?`)
 *  - BelongsToHotel trait (write isolation, stamps hotel_id on create)
 *
 * A NULL context means "platform scope" and is never allowed for tenant rows.
 */
final class HotelContext
{
    private static ?int $hotelId = null;

    private static ?Hotel $hotel = null;

    /**
     * Re-entrancy guard for the Sanctum auth resolution path.
     *
     * Eloquent's per-model HotelScope runs this same class: applying the scope
     * calls id() -> user() -> auth('sanctum')->user(), and resolving that user
     * makes Sanctum load the token's `tokenable` (a Guest) — whose query, once
     * again, applies the HotelScope -> id() -> user() ... and so on until
     * Xdebug aborts the script at 512 frames.
     *
     * Setting this flag while the user is being resolved makes those inner,
     * recursive calls bail out with null instead of re-entering auth, which
     * yields the *same* tenant value on the outermost frame without the loop.
     * Guest auth/login are unaffected: they run outside the tenant middleware
     * (and outside Sanctum's token resolution), so they never hit the guard.
     */
    private static bool $resolvingUser = false;

    /** Resolved lazily from container so tests can swap the request user. */
    public static function id(): ?int
    {
        if (self::$hotelId !== null) {
            return self::$hotelId;
        }

        $user = self::user();

        if (self::$hotelId === null) {
            self::$hotelId = $user?->hotel_id;
        }

        return self::$hotelId;
    }

    public static function set(Hotel|int $hotel): void
    {
        self::$hotelId = $hotel instanceof Hotel ? $hotel->id : $hotel;
        self::$hotel = $hotel instanceof Hotel ? $hotel : null;
    }

    public static function hotel(): ?Hotel
    {
        if (self::$hotel) {
            return self::$hotel;
        }

        return self::$hotel = self::id() ? Hotel::query()->withoutGlobalScopes()->find(self::$hotelId) : null;
    }

    public static function clear(): void
    {
        self::$hotelId = null;
        self::$hotel = null;
    }

    /**
     * The callable used as the Spatie team id so RBAC lookups are scoped to
     * the tenant (hotel) as well.
     */
    public static function teamId(): ?int
    {
        return self::id();
    }

    private static function user(): \App\Models\User|\App\Models\Guest|null
    {
        // Re-entrancy guard. The Sanctum tokenable (a Guest) carries this exact
        // HotelScope; loading it re-enters HotelContext::id() -> user(). When
        // the flag is already set we short-circuit with null so the inner,
        // recursive frame gets no tenant instead of calling auth() again (which
        // would resolve the token -> tokenable -> scope -> ... until Xdebug
        // kills the script at 512 frames).
        if (self::$resolvingUser) {
            return null;
        }

        self::$resolvingUser = true;
        try {
            return auth('sanctum')->user() ?? auth()->user();
        } finally {
            self::$resolvingUser = false;
        }
    }
}