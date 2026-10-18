<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * See the create_booking_intents_table migration for the "pay first, reserve
 * after" rationale. An intent becomes a real Booking only once its PayDunya
 * payment is confirmed.
 */
class BookingIntent extends Model
{
    use BelongsToHotel, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hotel_id', 'token', 'status', 'guest_first_name', 'guest_last_name',
        'guest_email', 'guest_phone', 'check_in', 'check_out', 'rooms',
        'adults', 'children', 'subtotal_cents', 'tax_cents', 'discount_cents',
        'total_cents', 'currency', 'source', 'notes', 'booking_id', 'paid_at',
    ];

    protected $casts = [
        'rooms' => 'array',
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'discount_cents' => 'integer',
        'total_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
