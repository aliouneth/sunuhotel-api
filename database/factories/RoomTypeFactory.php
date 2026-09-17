<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    public function definition(): array
    {
        $types = [
            ['Standard', 2, 2, 8000],
            ['Superior', 2, 3, 12000],
            ['Deluxe', 2, 4, 18000],
            ['Executive Suite', 3, 5, 32000],
            ['Family Room', 4, 6, 24000],
        ];

        [$name, $baseCap, $maxCap, $baseRate] = $this->faker->randomElement($types);

        return [
            'hotel_id' => Hotel::factory(),
            'name' => $name,
            'description' => $this->faker->sentence(6),
            'base_capacity' => $baseCap,
            'max_capacity' => $maxCap,
            'base_rate_cents' => $baseRate + $this->faker->numberBetween(0, 2000),
            'is_active' => true,
        ];
    }
}