<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word().' Plan',
            'max_rooms' => 10,
            'monthly_rate_cents' => 10000,
            'currency' => 'XOF',
            'is_active' => true,
        ];
    }
}