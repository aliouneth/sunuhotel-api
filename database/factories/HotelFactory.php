<?php

namespace Database\Factories;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class HotelFactory extends Factory
{
    protected $model = Hotel::class;

    public function definition(): array
    {
        $name = $this->faker->company().' Hotel';

        return [
            'uuid' => (string) Str::uuid(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'country' => $this->faker->countryCode(),
            'city' => $this->faker->city(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->safeEmail(),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'tax_rate' => 8,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'status' => 'active',
        ];
    }
}