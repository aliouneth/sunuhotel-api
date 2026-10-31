<?php
namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestReviewSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_rate_completed_stay(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active']);
        $guest = Guest::factory()->create([
            'hotel_id' => $hotel->id,
            'first_name' => 'Awa',
            'last_name' => 'Sow',
            'email' => 'awa.sow@example.com',
        ]);
        $booking = Booking::factory()->create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'booking_number' => 'SUNUHO-00001',
            'status' => 'checked_out',
        ]);

        $token = $guest->createToken('test')->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/guest/me/reviews', [
                'booking_id' => $booking->id,
                'rating' => 4,
                'title' => 'Bien',
                'comment' => 'Bon sejour.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4);

        $this->assertDatabaseHas('reviews', [
            'hotel_id' => $hotel->id,
            'booking_id' => $booking->id,
            'rating' => 4,
            'verified' => true,
            'status' => 'published',
        ]);
    }

    public function test_guest_cannot_rate_incomplete_stay(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active']);
        $guest = Guest::factory()->create(['hotel_id' => $hotel->id, 'email' => 'awa.sow@example.com']);
        $booking = Booking::factory()->create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'booking_number' => 'SUNUHO-00002',
            'status' => 'confirmed',
        ]);

        $token = $guest->createToken('test')->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/guest/me/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'STAY_NOT_COMPLETED');
    }

    public function test_guest_cannot_rate_twice(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active']);
        $guest = Guest::factory()->create(['hotel_id' => $hotel->id, 'email' => 'awa.sow@example.com']);
        $booking = Booking::factory()->create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'booking_number' => 'SUNUHO-00003',
            'status' => 'checked_out',
        ]);

        $token = $guest->createToken('test')->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/guest/me/reviews', ['booking_id' => $booking->id, 'rating' => 5])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/guest/me/reviews', ['booking_id' => $booking->id, 'rating' => 3])
            ->assertStatus(422)
            ->assertJsonPath('error', 'STAY_ALREADY_REVIEWED');
    }
}
