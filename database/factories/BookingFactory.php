<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Guest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $checkIn = Carbon::today()->addDays($this->faker->numberBetween(0, 20));
        $checkOut = (clone $checkIn)->addDays($this->faker->numberBetween(1, 7));
        $subtotal = $this->faker->numberBetween(50, 400) * 100;

        return [
            'hotel_id' => fn () => Guest::factory()->create()->hotel_id,
            'guest_id' => Guest::factory(),
            'status' => 'confirmed',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $this->faker->numberBetween(1, 3),
            'children' => $this->faker->numberBetween(0, 2),
            'source' => 'front_desk',
            'subtotal_cents' => $subtotal,
            'tax_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => $subtotal,
            'paid_cents' => 0,
        ];
    }
}