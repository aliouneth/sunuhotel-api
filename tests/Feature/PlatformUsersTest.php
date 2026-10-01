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

class PlatformUsersTest extends TestCase
{
    use RefreshDatabase;

    private function tenantStaff(string $email = 'staff@example.org'): User
    {
        $hotel = Hotel::factory()->create(['status' => 'active', 'name' => 'Hotel Alpha']);
        $user = User::factory()->create([
            'hotel_id' => $hotel->id,
            'email' => $email,
            'name' => 'Hotel Staff',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', 'manager')->first()]);
        HotelContext::clear();

        return $user;
    }

    public function test_users_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/platform/users')->assertUnauthorized();
    }

    public function test_users_listing_forbids_hotel_staff(): void
    {
        Role::seedPolicies();
        $staff = $this->tenantStaff();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/platform/users')
            ->assertForbidden();
    }

    public function test_listing_returns_only_platform_users_and_excludes_hotel_staff(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create([
            'name' => 'Platform Boss',
            'email' => 'boss@sunuhotel.example',
        ]);
        $this->tenantStaff('clerk@hotel.example');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/users');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $admin->id)
            ->assertJsonPath('data.0.email', 'boss@sunuhotel.example')
            ->assertJsonPath('data.0.name', 'Platform Boss');

        $emails = array_column($response->json('data'), 'email');
        $this->assertNotContains('clerk@hotel.example', $emails);
    }

    public function test_listing_exposes_no_hotel_or_role_fields(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();

        $row = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('hotel', $row);
        $this->assertArrayNotHasKey('roles', $row);
    }

    public function test_platform_admin_can_deactivate_another_platform_user(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();
        $other = User::factory()->platformAdmin()->create(['is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/users/'.$other->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $other->fresh()->is_active);
    }

    public function test_platform_admin_cannot_deactivate_themselves(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create(['is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/users/'.$admin->id, ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_platform_admin_can_rename_and_translate_a_platform_user(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();
        $other = User::factory()->platformAdmin()->create(['name' => 'Old', 'locale' => 'fr']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/users/'.$other->id, ['name' => 'New Name', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_hotel_staff_account_is_not_reachable_through_the_platform_route(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();
        $staff = $this->tenantStaff();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/users/'.$staff->id, ['is_active' => false])
            ->assertNotFound();

        $this->assertTrue((bool) $staff->fresh()->is_active);
    }

    public function test_hotel_staff_cannot_create_platform_users(): void
    {
        Role::seedPolicies();
        $staff = $this->tenantStaff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Backdoor',
                'email' => 'backdoor@sunuhotel.example',
                'password' => 'Str0ngPassw0rd',
                'password_confirmation' => 'Str0ngPassw0rd',
            ])
            ->assertForbidden();
    }

    public function test_platform_admin_can_create_a_platform_user(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/users', [
            'name' => 'New Operator',
            'email' => 'operator@sunuhotel.example',
            'password' => 'Str0ngPassw0rd',
            'password_confirmation' => 'Str0ngPassw0rd',
            'locale' => 'fr',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Operator')
            ->assertJsonPath('data.email', 'operator@sunuhotel.example')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.is_active', true);

        $created = User::where('email', 'operator@sunuhotel.example')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->hotel_id, 'A platform user must not be attached to a hotel.');
        $this->assertTrue($created->isPlatformAdmin());
    }

    public function test_created_user_password_is_hashed_and_never_returned(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/users', [
            'name' => 'Hashed',
            'email' => 'hashed@sunuhotel.example',
            'password' => 'Str0ngPassw0rd',
            'password_confirmation' => 'Str0ngPassw0rd',
        ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissing(['password' => 'Str0ngPassw0rd']);

        $created = User::where('email', 'hashed@sunuhotel.example')->first();
        $this->assertNotSame('Str0ngPassw0rd', $created->password);
        $this->assertTrue(Hash::check('Str0ngPassw0rd', $created->password));
    }

    public function test_new_platform_user_appears_in_the_listing_and_can_sign_in(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/users', [
            'name' => 'Login Test',
            'email' => 'login@sunuhotel.example',
            'password' => 'Str0ngPassw0rd',
            'password_confirmation' => 'Str0ngPassw0rd',
        ])->assertCreated();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?q=login@')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'login@sunuhotel.example');

        $this->assertCount(1, $response->json('data'));

        // The new account authenticates against the normal login endpoint.
        $this->postJson('/api/v1/login', [
            'email' => 'login@sunuhotel.example',
            'password' => 'Str0ngPassw0rd',
        ])->assertOk();
    }

    public function test_creation_rejects_a_duplicate_email(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create(['email' => 'taken@sunuhotel.example']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Copycat',
                'email' => 'taken@sunuhotel.example',
                'password' => 'Str0ngPassw0rd',
                'password_confirmation' => 'Str0ngPassw0rd',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_creation_rejects_a_weak_or_unconfirmed_password(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Weak',
                'email' => 'weak@sunuhotel.example',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/users', [
                'name' => 'Mismatch',
                'email' => 'mismatch@sunuhotel.example',
                'password' => 'Str0ngPassw0rd',
                'password_confirmation' => 'Different12345',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_creation_requires_name_email_and_password(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_creation_requires_authentication(): void
    {
        $this->postJson('/api/v1/platform/users', [
            'name' => 'Anon',
            'email' => 'anon@sunuhotel.example',
            'password' => 'Str0ngPassw0rd',
            'password_confirmation' => 'Str0ngPassw0rd',
        ])->assertUnauthorized();
    }

    public function test_listing_can_filter_by_active_status(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create(['is_active' => true]);
        User::factory()->platformAdmin()->create(['is_active' => false, 'email' => 'off@sunuhotel.example']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?status=inactive')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'off@sunuhotel.example');
    }

    public function test_listing_can_search_by_email(): void
    {
        Role::seedPolicies();
        $admin = User::factory()->platformAdmin()->create(['email' => 'alpha@sunuhotel.example']);
        User::factory()->platformAdmin()->create(['email' => 'beta@sunuhotel.example']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?q=beta@')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'beta@sunuhotel.example');
    }
}
