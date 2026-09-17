<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rich demo dataset for local development: one active hotel with a full room
 * inventory, one user per role, guests and a month of real bookings/payments.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Role::seedPolicies();

        $hotel = Hotel::create([
            'uuid' => (string) Str::uuid(),
            'slug' => Hotel::SAMPLE_HOTEL_SLUG,
            'name' => 'Sunuhotel Dakar',
            'legal_name' => 'Sunuhotel Dakar SARL',
            'address' => 'Route des Almadies',
            'city' => 'Dakar',
            'country' => 'SN',
            'phone' => '+221 33 800 00 00',
            'email' => 'reservations@sunuhotel.example',
            'website' => 'https://sunuhotel.example',
            'timezone' => 'Africa/Dakar',
            'currency' => 'XOF',
            'tax_rate' => 18,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'logo_path' => null,
            'status' => 'active',
        ]);

        $users = [];
        $users['owner'] = $this->user($hotel, 'Awa Ndiaye', 'owner@sunuhotel.example', 'Owner');
        $users['manager'] = $this->user($hotel, 'Moussa Diallo', 'manager@sunuhotel.example', 'Manager');
        $users['front-desk'] = $this->user($hotel, 'Fatou Sarr', 'frontdesk@sunuhotel.example', 'Front Desk');
        $users['accountant'] = $this->user($hotel, 'Aminata Ba', 'accountant@sunuhotel.example', 'Accountant');

        // Room types + one rate plan each.
        $typeDefs = [
            ['Standard', 'Single/Double standard room', 1, 2, 45000],
            ['Superior', 'Queen bed, city view', 2, 3, 75000],
            ['Deluxe', 'King bed, sea view', 2, 4, 110000],
            ['Executive Suite', 'Living room + terrace', 3, 5, 180000],
        ];

        foreach ($typeDefs as [$name, $desc, $min, $max, $rate]) {
            $type = RoomType::create([
                'hotel_id' => $hotel->id,
                'name' => $name,
                'description' => $desc,
                'base_capacity' => $min,
                'max_capacity' => $max,
                'base_rate_cents' => $rate,
                'features' => ['wifi' => true],
                'is_active' => true,
            ]);

            $hotel->ratePlans()->create([
                'room_type_id' => $type->id,
                'name' => $name.' — BAR (Best Available Rate)',
                'currency' => 'XOF',
                'base_rate_cents' => $rate,
                'basis' => 'daily',
                'days_rules' => ['sat' => 1.15, 'sun' => 1.10],
                'season_rules' => [
                    ['start' => '12-20', 'end' => '01-05', 'multiplier' => 1.5],
                    ['start' => '07-01', 'end' => '08-31', 'multiplier' => 1.25],
                ],
                'tax_included' => false,
                'is_active' => true,
            ]);
        }

        // Physical rooms.
        $byType = $hotel->roomTypes;
        foreach ($byType as $idx => $type) {
            for ($i = 1; $i <= 5; $i++) {
                Room::create([
                    'hotel_id' => $hotel->id,
                    'room_number' => ($idx + 1).'0'.$i,
                    'floor' => $idx + 1,
                    'room_type_id' => $type->id,
                    'capacity' => $type->base_capacity,
                    'status' => 'available',
                ]);
            }
        }

        // Guests.
        $guests = collect(range(1, 12))->map(function () use ($hotel) {
            return Guest::create([
                'hotel_id' => $hotel->id,
                'first_name' => fake()->firstName,
                'last_name' => fake()->lastName,
                'email' => fake()->safeEmail,
                'phone' => fake()->phoneNumber,
                'nationality' => fake('fr_FR')->countryCode,
                'id_type' => 'passport',
                'id_number' => (string) fake()->numberBetween(1000000, 9999999),
                'preferences' => fake()->boolean ? ['floor' => 'high', 'smoking' => false] : null,
            ]);
        });

        // Bookings across the last 25 days.
        $rooms = $hotel->rooms;
        $ratePlans = $hotel->ratePlans;

        foreach (range(0, 24) as $offset) {
            $checkIn = Carbon::today()->subDays($offset)->setTime(15, 0);
            $length = rand(1, 4);
            $checkOut = (clone $checkIn)->addDays($length);
            $guestsNum = rand(1, 2);
            $selected = $guests->random($guestsNum);
            $room = $rooms->random();
            $plan = $ratePlans->where('room_type_id', $room->room_type_id)->first();

            if (! $plan) {
                continue;
            }

            $nightly = $plan->base_rate_cents;
            $subtotal = $nightly * $length;
            $status = $checkOut->lt(today())
                ? collect(['checked_out', 'checked_out', 'checked_out', 'cancelled'])->random()
                : 'confirmed';

            $booking = Booking::create([
                'hotel_id' => $hotel->id,
                'booking_number' => $hotel->nextBookingNumber(),
                'guest_id' => $selected->first()->id,
                'status' => $status,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => 1,
                'children' => 0,
                'source' => collect(['front_desk', 'website', 'walk_in'])->random(),
                'subtotal_cents' => $subtotal,
                'tax_cents' => (int) round($subtotal * 0.18),
                'discount_cents' => 0,
                'total_cents' => (int) round($subtotal * 1.18),
                'paid_cents' => $status === 'checked_out' ? (int) round($subtotal * 1.18) : 0,
                'cancelled_at' => $status === 'cancelled' ? now()->subDay() : null,
                'created_by' => $users['front-desk']->id,
                'created_by_name' => $users['front-desk']->name,
            ]);

            BookingRoom::create([
                'hotel_id' => $hotel->id,
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'rate_plan_id' => $plan->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $length,
                'nightly_rate_cents' => $nightly,
                'line_total_cents' => $subtotal,
                'active' => $status !== 'cancelled',
            ]);

            if ($status === 'checked_out') {
                Payment::create([
                    'hotel_id' => $hotel->id,
                    'booking_id' => $booking->id,
                    'guest_id' => $selected->first()->id,
                    'amount_cents' => $booking->total_cents,
                    'method' => 'cash',
                    'status' => 'completed',
                    'paid_at' => $checkOut,
                    'received_by' => $users['front-desk']->id,
                ]);
            }
        }

        $this->command?->info('Demo hotel "Sunuhotel Dakar" seeded.');
    }

    private function user(Hotel $hotel, string $name, string $email, string $label): User
    {
        $user = User::create([
            'hotel_id' => $hotel->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'locale' => 'fr',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->assignRole(\App\Models\Role::query()->where('name', $label === 'Front Desk' ? 'front-desk' : strtolower($label))->first());

        return $user;
    }
}