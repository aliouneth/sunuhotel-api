<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global query scope that fists every tenant-owned model query inside the
 * current hotel context. Combined with the unique(FOR UPDATE) transaction
 * guard in BookingService this provides defense-in-depth isolation.
 */
class HotelScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (($hotelId = HotelContext::id()) !== null) {
            $builder->where($model->qualifyColumn('hotel_id'), $hotelId);
        }
    }
}