<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RatePlan extends Model
{
    use BelongsToHotel, HasFactory, SoftDeletes;

    public const BASES = ['daily', 'weekly', 'seasonal'];

    protected $fillable = [
        'hotel_id', 'room_type_id', 'name', 'currency', 'base_rate_cents',
        'basis', 'week_multiplier', 'days_rules', 'season_rules',
        'tax_included', 'valid_from', 'valid_to', 'is_active',
    ];

    protected $casts = [
        'days_rules' => 'array',
        'season_rules' => 'array',
        'tax_included' => 'boolean',
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'week_multiplier' => 'float',
        'base_rate_cents' => 'integer',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function overrides()
    {
        return $this->hasMany(RateOverride::class);
    }

    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class);
    }
}