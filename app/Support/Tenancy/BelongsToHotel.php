<?php

namespace App\Support\Tenancy;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenancy trait for every tenant-owned model.
 *
 * - Adds the HotelScope global query scope (READ isolation).
 * - Stamps hotel_id on create from HotelContext (WRITE isolation).
 * - Declares the hotel() relation and a helper to bypass the scope when
 *   absolutely required (e.g. platform bootstrap inside the tenant middleware,
 *   booking reports on historical data).
 *
 * @property int|null $hotel_id
 */
trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        static::addGlobalScope(new HotelScope());

        static::creating(function (Model $model) {
            if ($model->hotel_id === null && ($context = HotelContext::id()) !== null) {
                $model->hotel_id = $context;
            }
        });
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** Query builder convenience for the hotel-scoped queries. */
    public function scopeWithinHotel(Builder $query, int $hotelId): Builder
    {
        return $query->withoutGlobalScopes()->where($this->qualifyColumn('hotel_id'), $hotelId);
    }
}