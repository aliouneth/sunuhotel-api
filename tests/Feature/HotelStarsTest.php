<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HotelStarsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create(['status' => 'active']);
    }

    public function test_platform_admin_can_set_hotel_stars_validation_and_persist(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$this->hotel->id}", ['stars' => 5])
            ->assertOk()
            ->assertJsonPath('data.stars', 5);
        $this->assertSame(5, $this->hotel->fresh()->stars);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$this->hotel->id}", ['stars' => 6])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stars');
        $this->assertSame(5, $this->hotel->fresh()->stars);
    }

    public function test_tenant_owner_can_set_hotel_stars(): void
    {
        $owner = User::factory()->create(['hotel_id' => $this->hotel->id]);
        Role::seedPolicies();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->hotel->id);
        $owner->syncRoles([Role::query()->where('name', 'owner')->first()]);
        HotelContext::clear();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/hotel', ['stars' => 4])
            ->assertOk()
            ->assertJsonPath('data.stars', 4);
        $this->assertSame(4, $this->hotel->fresh()->stars);
    }

    public function test_stars_are_serialized_on_public_profile_and_search(): void
    {
        $this->hotel->update(['stars' => 3]);

        $this->getJson("/api/v1/hotels/{$this->hotel->slug}/public")
            ->assertOk()
            ->assertJsonPath('data.stars', 3);

        $this->getJson('/api/v1/hotels/search?q=' . rawurlencode($this->hotel->name))
            ->assertOk()
            ->assertJsonPath('data.0.stars', 3);
    }
}
