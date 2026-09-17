<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Platform user. For the MVP each user is attached to exactly one hotel
 * (tenant) — the strictest isolation model. `hotel_id` is nullable only for
 * platform administrators.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'hotel_id', 'name', 'email', 'password', 'locale', 'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function isPlatformAdmin(): bool
    {
        // A user without a bound hotel is a platform administrator.
        return $this->hotel_id === null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}