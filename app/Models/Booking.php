<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    use BelongsToHotel, HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'];

    protected $fillable = [
        'hotel_id', 'booking_number', 'guest_id', 'status', 'check_in', 'check_out',
        'adults', 'children', 'source', 'subtotal_cents', 'tax_cents',
        'discount_cents', 'total_cents', 'paid_cents', 'notes',
        'cancellation_reason', 'cancelled_by', 'cancelled_at',
        'created_by', 'created_by_name',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'cancelled_at' => 'datetime',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'discount_cents' => 'integer',
        'total_cents' => 'integer',
        'paid_cents' => 'integer',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    protected $appends = ['nights', 'balance_due_cents'];

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function rooms()
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getNightsAttribute(): int
    {
        return Carbon::parse($this->check_in)->diffInDays(Carbon::parse($this->check_out)) ?: 1;
    }

    public function getBalanceDueCentsAttribute(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'confirmed', 'checked_in']);
    }
}