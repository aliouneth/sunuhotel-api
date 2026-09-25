<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assignment of a hotel to a subscription plan over a timeframe. Rows are
 * append-only history: the current assignment has effective_to = null, and any
 * upgrade/downgrade closes the active row (sets effective_to) before opening a
 * new one. Deactivation for non-payment sets status to "deactivated".
 */
class HotelSubscription extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'hotel_id',
        'subscription_plan_id',
        'effective_from',
        'effective_to',
        'status',
        'deactivation_reason',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MonthlyInvoice::class, 'hotel_subscription_id');
    }
}
