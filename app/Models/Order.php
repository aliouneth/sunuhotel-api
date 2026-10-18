<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An on-site order (restaurant / spa / leisure) raised while a guest is in
 * house. Orders are itemised via OrderItem and untaxed; their active total is
 * rolled into the booking as `orders_cents`.
 */
class Order extends Model
{
    use BelongsToHotel, HasFactory;

    public const CATEGORIES = ['restaurant', 'spa', 'leisure'];

    public const STATUSES = ['open', 'void'];

    /** Booking statuses during which orders may be added, edited or voided. */
    public const EDITABLE_BOOKING_STATUSES = ['pending', 'confirmed', 'checked_in'];

    protected $fillable = [
        'hotel_id', 'booking_id', 'category', 'status', 'total_cents',
        'notes', 'created_by', 'created_by_name',
    ];

    protected $casts = [
        'total_cents' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
