<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-managed default tax rate (VAT) per country, keyed by ISO 3166-1
 * alpha-2 country code. Used ONLY as a pure fallback: when a hotel's own
 * tax_rate is 0 (or the hotel has no tax_rate), reservations in that country
 * fall back to this platform default. A hotel's own tax_rate always wins.
 */
class CountryTaxRate extends Model
{
    protected $fillable = ['country_code', 'tax_rate'];

    protected $casts = [
        'tax_rate' => 'float',
    ];

    /**
     * Effective tax percentage for a hotel booking:
     *  - hotel's own tax_rate when > 0 (always wins);
     *  - otherwise the platform country rate for the hotel's country;
     *  - otherwise 0.
     */
    public static function effectiveRateForHotel(?Hotel $hotel): float
    {
        if ($hotel && $hotel->tax_rate > 0) {
            return (float) $hotel->tax_rate;
        }

        if ($hotel && $hotel->country) {
            return static::rateFor($hotel->country);
        }

        return 0.0;
    }

    /**
     * The platform tax percentage for a country, or 0 when unset.
     */
    public static function rateFor(?string $countryCode): float
    {
        if (! $countryCode) {
            return 0.0;
        }

        return (float) (static::query()->where('country_code', strtoupper($countryCode))->value('tax_rate') ?? 0.0);
    }
}
