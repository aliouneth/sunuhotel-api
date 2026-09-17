<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        $roomType = RoomType::factory()->create();

        return [
            'hotel_id' => $roomType->hotel_id,
            'room_number' => $this->faker->unique()->numberBetween(100, 999),
            'floor' => $this->faker->numberBetween(0, 4),
            'room_type_id' => $roomType->id,
            'capacity' => $roomType->base_capacity,
            'status' => 'available',
        ];
    }
}