<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use BelongsToHotel, HasFactory, SoftDeletes;

    public const ID_TYPES = ['passport', 'national_id', 'driver_license', 'other'];

    protected $fillable = [
        'hotel_id', 'first_name', 'last_name', 'email', 'phone', 'nationality',
        'id_type', 'id_number', 'date_of_birth', 'address', 'city', 'country',
        'preferences', 'notes', 'is_blacklisted', 'created_by',
    ];

    protected $casts = [
        'preferences' => 'array',
        'date_of_birth' => 'date',
        'is_blacklisted' => 'boolean',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Guest's booking history, most recent first.
     */
    public function stayHistory(int $limit = 20)
    {
        return $this->bookings()->latest('check_in')->limit($limit);
    }
}