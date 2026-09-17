<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $role = 'owner'): array
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->create(['hotel_id' => $hotel->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', $role)->first()]);

        // Domain rows for tenant A.
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
        Guest::factory()->create(['hotel_id' => $hotel->id]);

        HotelContext::clear();

        return [$hotel, $user];
    }

    public function test_hotel_a_cannot_read_hotel_b_rooms(): void
    {
        [$hotelA, $userA] = $this->makeTenant();
        [$hotelB, $userB] = $this->makeTenant();

        // User B (own tenant: hotel B) must only count its own 1 room.
        $response = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/v1/rooms?per_page=50');

        $response->assertOk()
            ->assertJson(['data' => ['total' => 1]]);

        // User A already saw 1 too (its own).
        $responseA = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/rooms?per_page=50');

        $responseA->assertOk()->assertJson(['data' => ['total' => 1]]);
    }

    public function test_tenant_scope_applies_to_write_stamping(): void
    {
        [$hotel, $user] = $this->makeTenant();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rooms', [
                'room_number' => '999',
                'room_type_id' => $hotel->roomTypes()->first()->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('rooms', [
            'room_number' => '999',
            'hotel_id' => $hotel->id,
        ]);
    }

    public function test_user_cannot_act_on_foreign_booking(): void
    {
        [$hotelA, $userA] = $this->makeTenant();
        [$hotelB, $userB] = $this->makeTenant();

        $foreignRoom = $hotelA->rooms()->first();

        // User B tries to mutate a room owned by hotel A — scoped query returns 404.
        $this->actingAs($userB, 'sanctum')
            ->patchJson("/api/v1/rooms/{$foreignRoom->id}/status", ['status' => 'dirty'])
            ->assertNotFound();
    }

    public function test_platform_admin_can_switch_with_x_hotel_header(): void
    {
        [$hotel, $user] = $this->makeTenant();

        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        // Without header: platform admin is refused from tenant routes.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/rooms')
            ->assertForbidden();

        // With X-Hotel: allowed into the chosen tenant.
        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Hotel', $hotel->id)
            ->getJson('/api/v1/rooms')
            ->assertOk()
            ->assertJson(['data' => ['total' => 1]]);

        HotelContext::clear();
    }
}