<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'nationality' => $this->faker->countryCode(),
            'id_type' => $this->faker->randomElement(['passport', 'national_id']),
            'id_number' => $this->faker->numerify('########'),
        ];
    }
}