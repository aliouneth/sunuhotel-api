<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Client rating left for a hotel after a verified (completed) stay.
 */
class Review extends Model
{
    use BelongsToHotel, HasFactory;

    public const STATUSES = ['published', 'hidden'];

    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    protected $fillable = [
        'hotel_id', 'booking_id', 'guest_name', 'rating', 'title',
        'comment', 'status', 'verified',
    ];

    protected $casts = [
        'rating' => 'integer',
        'verified' => 'boolean',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Masked display name for public pages (best practice: never expose full
     * names publicly) — e.g. "Awa S."
     */
    public function getPublicAuthorAttribute(): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($this->guest_name))));

        if (! $parts) {
            return 'Guest';
        }

        $first = $parts[0];
        $last = count($parts) > 1 ? $parts[array_key_last($parts)] : '';

        return $last && $last !== $first
            ? $first.' '.mb_substr($last, 0, 1).'.'
            : $first;
    }
}