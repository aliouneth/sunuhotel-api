<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseType extends Model
{
    use BelongsToHotel, HasFactory;

    /** Stable keys for built-in categories. */
    public const KEY_SALARY = 'salary';

    protected $fillable = [
        'hotel_id', 'name', 'key', 'color', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}