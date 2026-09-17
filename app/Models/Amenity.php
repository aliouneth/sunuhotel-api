<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    use BelongsToHotel, HasFactory;

    protected $fillable = [
        'hotel_id', 'name', 'icon',
    ];

    public function rooms()
    {
        return $this->belongsToMany(Room::class)
            ->withPivot('hotel_id');
    }
}