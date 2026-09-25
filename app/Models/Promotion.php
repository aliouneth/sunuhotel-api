<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform-created discount offer for a specific hotel room type over a date
 * window. Shown on the public homepage while the window is current, and billed
 * to the hotel (fee_cents is applied to the current monthly invoice via
 * BillingService::chargePromotion).
 *
 * Platform-owned (NOT hotel-scoped): created by platform admins, read publicly.
 */
class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id', 'room_type_id', 'title', 'starts_on', 'ends_on',
        'original_rate_cents', 'promo_rate_cents', 'currency',
        'fee_cents', 'is_active',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'original_rate_cents' => 'integer',
        'promo_rate_cents' => 'integer',
        'fee_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function scopeRunning($query)
    {
        return $query
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString());
    }

    /**
     * The most recent running promotion for each room type of a hotel, keyed by
     * room_type_id. Used to price the room type at the promo rate everywhere a
     * public reservation page quotes a nightly rate.
     */
    public static function runningByTypeForHotel(int $hotelId): \Illuminate\Support\Collection
    {
        return static::query()
            ->running()
            ->where('hotel_id', $hotelId)
            ->orderByDesc('id')
            ->get()
            ->unique('room_type_id')
            ->keyBy('room_type_id');
    }
}