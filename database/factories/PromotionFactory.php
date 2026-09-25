<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\Promotion;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'room_type_id' => RoomType::factory(),
            'title' => $this->faker->words(3, true),
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(),
            'original_rate_cents' => 60000,
            'promo_rate_cents' => 45000,
            'currency' => 'XOF',
            'fee_cents' => 0,
            'is_active' => true,
        ];
    }
}