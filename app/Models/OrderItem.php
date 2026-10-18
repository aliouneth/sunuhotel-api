<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single line on an on-site order (restaurant / spa / leisure).
 */
class OrderItem extends Model
{
    use BelongsToHotel, HasFactory;

    protected $fillable = [
        'hotel_id', 'order_id', 'description', 'quantity',
        'unit_price_cents', 'line_total_cents',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'line_total_cents' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
