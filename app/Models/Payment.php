<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToHotel, HasFactory;

    public const METHODS = ['cash', 'card', 'bank_transfer', 'mobile_money'];
    public const STATUSES = ['pending', 'completed', 'refunded', 'failed'];

    protected $fillable = [
        'hotel_id', 'booking_id', 'guest_id', 'amount_cents', 'method', 'status',
        'type', 'reference', 'paid_at', 'received_by', 'notes',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}