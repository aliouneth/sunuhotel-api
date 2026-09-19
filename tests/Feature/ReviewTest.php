<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Guest $guest;

    private Booking $completed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->create(['status' => 'active']);
        $this->guest = Guest::factory()->create([
            'hotel_id' => $this->hotel->id,
            'first_name' => 'Awa',
            'last_name' => 'Sow',
            'email' => 'awa.sow@example.com',
        ]);
        $this->completed = Booking::factory()->create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'booking_number' => 'SUNUHO-00001',
            'status' => 'checked_out',
        ]);
    }

    private function reviewPayload(array $overrides = []): array
    {
        return array_merge([
            'booking_number' => 'SUNUHO-00001',
            'email' => 'awa.sow@example.com',
            'rating' => 5,
            'title' => 'Excellent séjour',
            'comment' => 'Personnel accueillant et chambre très propre.',
        ], $overrides);
    }

    public function test_verified_completed_stay_can_publish_a_review(): void
    {
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload())
            ->assertCreated()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('reviews', [
            'hotel_id' => $this->hotel->id,
            'booking_id' => $this->completed->id,
            'status' => 'published',
        ]);

        $show = $this->getJson("/api/v1/hotels/{$this->hotel->slug}/public")->assertOk()->json('data');

        $this->assertSame(1, $show['rating']['count']);
        $this->assertSame(5.0, (float) $show['rating']['average']);
        $this->assertSame('Awa S.', $show['reviews'][0]['author']);
        $this->assertTrue($show['reviews'][0]['verified']);
    }

    public function test_review_requires_matching_reference_and_email(): void
    {
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload(['booking_number' => 'SUNUHO-99999']))
            ->assertStatus(422);

        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload(['email' => 'other@example.com']))
            ->assertStatus(422);
    }

    public function test_only_completed_stays_can_be_reviewed(): void
    {
        $pending = Booking::factory()->create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'booking_number' => 'SUNUHO-00002',
            'status' => 'confirmed',
        ]);

        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload(['booking_number' => 'SUNUHO-00002']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'STAY_NOT_COMPLETED');
    }

    public function test_a_booking_can_only_be_reviewed_once(): void
    {
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload())->assertCreated();
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload())
            ->assertStatus(422)
            ->assertJsonPath('error', 'STAY_ALREADY_REVIEWED');
    }

    public function test_platform_admin_can_hide_and_unhide_a_review(): void
    {
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload())->assertCreated();

        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        $list = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/platform/hotels/{$this->hotel->id}/reviews")
            ->assertOk();

        $reviewId = $list->json('data.0.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$this->hotel->id}/reviews/{$reviewId}/moderate", ['status' => 'hidden'])
            ->assertOk()
            ->assertJsonPath('data.status', 'hidden');

        $this->getJson("/api/v1/hotels/{$this->hotel->slug}/public")
            ->assertOk()
            ->assertJsonPath('data.rating.count', 0)
            ->assertJsonCount(0, 'data.reviews');
    }

    public function test_tenant_reviews_endpoint_returns_summary(): void
    {
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/reviews", $this->reviewPayload())->assertCreated();

        $owner = User::factory()->create(['hotel_id' => $this->hotel->id]);
        Role::seedPolicies();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->hotel->id);
        $owner->syncRoles([Role::query()->where('name', 'owner')->first()]);
        HotelContext::clear();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.average', 5)
            ->assertJsonCount(1, 'data.recent');
    }
}