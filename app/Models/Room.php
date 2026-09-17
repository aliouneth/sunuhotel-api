<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use BelongsToHotel, HasFactory, SoftDeletes;

    public const STATUSES = ['available', 'occupied', 'dirty', 'out_of_order', 'maintenance'];

    protected $fillable = [
        'hotel_id', 'room_number', 'floor', 'room_type_id', 'capacity',
        'daily_rate_cents', 'status', 'keycard_code', 'notes',
    ];

    protected $casts = [
        'floor' => 'integer',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function amenities()
    {
        return $this->belongsToMany(Amenity::class)
            ->withPivot('hotel_id');
    }

    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function availableForDates(string $checkIn, string $checkOut): bool
    {
        return ! $this->bookingRooms()
            ->where('active', true)
            ->whereDate('check_in', '<', $checkOut)
            ->whereDate('check_out', '>', $checkIn)
            ->exists();
    }
}