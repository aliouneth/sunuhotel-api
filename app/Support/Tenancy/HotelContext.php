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

    private static function user(): ?\App\Models\User
    {
        return auth('sanctum')->user() ?? auth()->user();
    }
}