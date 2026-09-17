<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function member(Hotel $hotel, string $role): User
    {
        Role::seedPolicies();

        $user = User::factory()->create(['hotel_id' => $hotel->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', $role)->first()]);
        HotelContext::clear();

        return $user;
    }

    public function test_me_exposes_roles_and_permissions_for_the_tenant_context(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = $this->member($hotel, 'owner');

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/me')->assertOk();

        $this->assertSame('owner', $response->json('data.roles.0.name'));
        $this->assertContains('reports.view', $response->json('data.permissions'));
        $this->assertContains('hotels.update', $response->json('data.permissions'));
    }

    public function test_front_desk_permissions_exclude_missing_ones(): void
    {
        $hotel = Hotel::factory()->create();
        $frontDesk = $this->member($hotel, 'front-desk');

        $response = $this->actingAs($frontDesk, 'sanctum')->getJson('/api/v1/me')->assertOk();
        $permissions = $response->json('data.permissions');

        $this->assertContains('bookings.view', $permissions);
        $this->assertContains('bookings.manage', $permissions);
        $this->assertNotContains('reports.view', $permissions);
        $this->assertNotContains('hotels.update', $permissions);
    }

    public function test_login_payload_includes_permissions(): void
    {
        $hotel = Hotel::factory()->create();
        $user = $this->member($hotel, 'manager');
        $user->forceFill(['password' => Hash::make('password123')])->save();

        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();

        $this->assertContains('rooms.view', $response->json('user.permissions'));
        $this->assertContains('reports.view', $response->json('user.permissions'));
    }
}
