<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RateOverride extends Model
{
    use BelongsToHotel, HasFactory;

    protected $fillable = [
        'hotel_id', 'rate_plan_id', 'date', 'rate_cents',
    ];

    protected $casts = [
        'date' => 'date',
        'rate_cents' => 'integer',
    ];

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }
}