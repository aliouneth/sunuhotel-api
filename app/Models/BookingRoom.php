<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingRoom extends Model
{
    use BelongsToHotel, HasFactory;

    protected $fillable = [
        'hotel_id', 'booking_id', 'room_id', 'rate_plan_id', 'check_in', 'check_out',
        'nights', 'nightly_rate_cents', 'line_total_cents', 'active', 'active_key',
        'checked_in_at', 'checked_out_at', 'checked_in_by_name',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'active' => 'boolean',
        'nights' => 'integer',
        'nightly_rate_cents' => 'integer',
        'line_total_cents' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }
}