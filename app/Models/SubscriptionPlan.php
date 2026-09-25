<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-wide subscription tier (e.g. Lite / Pro / Enterprise). Global to
 * the platform — NOT hotel-scoped. Hotels are assigned to one plan at a time
 * through HotelSubscription, and each hotel's monthly bill is a snapshot of the
 * plan rate for that billing month.
 */
class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'max_rooms',
        'monthly_rate_cents',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'max_rooms' => 'integer',
        'monthly_rate_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(HotelSubscription::class);
    }
}
