<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row store for Sunuhotel corporate/office information shown on the
 * public support endpoint and edited from the platform admin pages.
 */
class PlatformSettings extends Model
{
    public const SINGLE_ROW_ID = 1;

    protected $fillable = [
        'company_name', 'address', 'city', 'country',
        'phone', 'email', 'website', 'hours',
    ];

    public static function current(): self
    {
        return static::query()->findOrNew(self::SINGLE_ROW_ID);
    }

    public static function defaults(): array
    {
        return [
            'company_name' => 'Sunuhotel',
            'address' => 'Route des Almadies',
            'city' => 'Dakar',
            'country' => 'SN',
            'phone' => '+221 33 800 00 00',
            'email' => 'support@sunuhotel.example',
            'website' => 'https://sunuhotel.example',
            'hours' => 'Mon–Sat 9:00–18:00',
        ];
    }
}