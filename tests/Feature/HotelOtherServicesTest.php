<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Other Services" is a single free-text blob on hotels (spa, shuttle,
 * laundry, ...) that both the platform admin and the tenant owner can edit.
 */
class HotelOtherServicesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function ownerFor(Hotel $hotel): User
    {
        Role::seedPolicies();
        $owner = User::factory()->create(['hotel_id' => $hotel->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $owner->syncRoles([Role::query()->where('name', 'owner')->first()]);

        return $owner;
    }

    public function test_column_exists_on_the_hotels_table(): void
    {
        $this->assertContains('other_services', \Schema::getColumnListing('hotels'));
    }

    public function test_field_is_mass_assignable(): void
    {
        $hotel = Hotel::create([
            'name' => 'Services Fillable Check',
            'other_services' => "Spa\nAirport shuttle\nLaundry",
        ]);

        $this->assertSame("Spa\nAirport shuttle\nLaundry", $hotel->other_services);
    }

    public function test_field_defaults_to_null(): void
    {
        $hotel = Hotel::factory()->create();

        $this->assertNull($hotel->other_services);
    }

    public function test_admin_can_create_a_hotel_with_other_services(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'Services Hotel',
                'other_services' => 'Spa, Airport shuttle, Laundry service',
            ])
            ->assertCreated()
            ->assertJsonPath('data.other_services', 'Spa, Airport shuttle, Laundry service');

        $hotel = Hotel::where('name', 'Services Hotel')->first();
        $this->assertSame('Spa, Airport shuttle, Laundry service', $hotel->other_services);
    }

    public function test_admin_can_edit_other_services(): void
    {
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['other_services' => 'Spa']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'other_services' => 'Spa, Airport shuttle',
            ])
            ->assertOk()
            ->assertJsonPath('data.other_services', 'Spa, Airport shuttle');

        $this->assertSame('Spa, Airport shuttle', $hotel->fresh()->other_services);
    }

    public function test_tenant_owner_can_save_other_services_from_hotel_settings(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active']);
        $owner = $this->ownerFor($hotel);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/hotel', [
                'other_services' => 'Restaurant, Pool, Free parking',
            ])
            ->assertOk();

        $this->assertSame('Restaurant, Pool, Free parking', $hotel->fresh()->other_services);
    }

    public function test_tenant_hotel_show_returns_other_services(): void
    {
        $hotel = Hotel::factory()->create(['other_services' => 'Gym, Sauna']);
        $owner = $this->ownerFor($hotel);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/hotel')
            ->assertOk()
            ->assertJsonPath('data.other_services', 'Gym, Sauna');
    }

    public function test_other_services_can_be_cleared_back_to_null(): void
    {
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['other_services' => 'Spa']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['other_services' => null])
            ->assertOk();

        $this->assertNull($hotel->fresh()->other_services);
    }

    public function test_over_long_value_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'Too Long Services',
                'other_services' => str_repeat('a', 5001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('other_services');
    }

    public function test_non_string_value_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'Bad Type Services',
                'other_services' => ['Spa', 'Gym'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('other_services');
    }

    public function test_newlines_and_accents_are_preserved_verbatim(): void
    {
        $admin = $this->admin();
        $value = "Spa & sauna\nNavette aéroport\nPiscine";
        $hotel = Hotel::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['other_services' => $value])
            ->assertOk();

        $this->assertSame($value, $hotel->fresh()->other_services);
    }

    public function test_surrounding_whitespace_is_trimmed_but_inner_spacing_is_preserved(): void
    {
        // Laravel's default TrimStrings middleware strips the outer edges of
        // every field; what matters for free text is that inner spacing and
        // line structure survive untouched.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'other_services' => "  Spa,   wellnes  \n  Gym  ",
            ])
            ->assertOk();

        $this->assertSame(
            "Spa,   wellnes  \n  Gym",
            $hotel->fresh()->other_services
        );
    }

    public function test_an_empty_submission_clears_the_field(): void
    {
        // Laravel's ConvertEmptyStringsToNull middleware turns a blank textarea
        // into null, so clearing the box in the UI works without a special case.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['other_services' => 'Spa']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['other_services' => ''])
            ->assertOk();

        $this->assertNull($hotel->fresh()->other_services);
    }
}
