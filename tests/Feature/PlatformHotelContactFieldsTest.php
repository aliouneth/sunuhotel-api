<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHotelContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    public function test_columns_exist_on_the_hotels_table(): void
    {
        $columns = \Schema::getColumnListing('hotels');

        $this->assertContains('phone_2', $columns);
        $this->assertContains('description', $columns);
        $this->assertContains('comment', $columns);
    }

    public function test_fields_are_mass_assignable(): void
    {
        $hotel = Hotel::create([
            'name' => 'Fillable Check',
            'phone_2' => '+221 77 000 00 00',
            'description' => 'A lovely place.',
            'comment' => 'Internal note.',
        ]);

        $this->assertSame('+221 77 000 00 00', $hotel->phone_2);
        $this->assertSame('A lovely place.', $hotel->description);
        $this->assertSame('Internal note.', $hotel->comment);
    }

    public function test_admin_can_create_a_hotel_with_the_new_fields(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'Contact Hotel',
                'phone' => '+221 33 111 11 11',
                'phone_2' => '+221 77 222 22 22',
                'description' => 'Beachfront property with 40 rooms.',
                'comment' => 'Chairs replacement scheduled for Q4.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.phone_2', '+221 77 222 22 22')
            ->assertJsonPath('data.description', 'Beachfront property with 40 rooms.')
            ->assertJsonPath('data.comment', 'Chairs replacement scheduled for Q4.');

        $hotel = Hotel::where('name', 'Contact Hotel')->first();
        $this->assertSame('+221 77 222 22 22', $hotel->phone_2);
        $this->assertSame('Beachfront property with 40 rooms.', $hotel->description);
        $this->assertSame('Chairs replacement scheduled for Q4.', $hotel->comment);
    }

    public function test_admin_can_edit_the_new_fields(): void
    {
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['name' => 'Editable']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'phone_2' => '+221 70 555 55 55',
                'description' => 'Updated description.',
                'comment' => 'Updated comment.',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_2', '+221 70 555 55 55')
            ->assertJsonPath('data.description', 'Updated description.')
            ->assertJsonPath('data.comment', 'Updated comment.');

        $fresh = $hotel->fresh();
        $this->assertSame('+221 70 555 55 55', $fresh->phone_2);
        $this->assertSame('Updated description.', $fresh->description);
        $this->assertSame('Updated comment.', $fresh->comment);
    }

    public function test_tenant_can_save_the_new_fields_from_hotel_settings(): void
    {
        Role::seedPolicies();
        $hotel = Hotel::factory()->create(['status' => 'active']);
        $owner = User::factory()->create(['hotel_id' => $hotel->id]);

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $owner->syncRoles([Role::query()->where('name', 'owner')->first()]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/hotel', [
                'phone_2' => '+221 78 999 99 99',
                'description' => 'Tenant supplied description.',
                'comment' => 'Tenant internal note.',
            ])
            ->assertOk();

        $fresh = $hotel->fresh();
        $this->assertSame('+221 78 999 99 99', $fresh->phone_2);
        $this->assertSame('Tenant supplied description.', $fresh->description);
        $this->assertSame('Tenant internal note.', $fresh->comment);
    }

    public function test_tenant_hotel_show_returns_the_new_fields(): void
    {
        Role::seedPolicies();
        $hotel = Hotel::factory()->create([
            'phone_2' => '+221 76 111 22 33',
            'description' => 'Visible description.',
            'comment' => 'Visible comment.',
        ]);
        $owner = User::factory()->create(['hotel_id' => $hotel->id]);

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $owner->syncRoles([Role::query()->where('name', 'owner')->first()]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/hotel')
            ->assertOk()
            ->assertJsonPath('data.phone_2', '+221 76 111 22 33')
            ->assertJsonPath('data.description', 'Visible description.')
            ->assertJsonPath('data.comment', 'Visible comment.');
    }

    public function test_fields_can_be_cleared_back_to_null(): void
    {
        $admin = $this->admin();
        $hotel = Hotel::factory()->create([
            'phone_2' => '+221 70 000 00 00',
            'description' => 'To be cleared.',
            'comment' => 'To be cleared.',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'phone_2' => null,
                'description' => null,
                'comment' => null,
            ])
            ->assertOk();

        $fresh = $hotel->fresh();
        $this->assertNull($fresh->phone_2);
        $this->assertNull($fresh->description);
        $this->assertNull($fresh->comment);
    }

    public function test_over_long_values_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'Too Long',
                'phone_2' => str_repeat('9', 41),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_2');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'Too Long',
                'description' => str_repeat('a', 5001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    public function test_new_fields_are_absent_from_the_hotel_listing_payload_when_null(): void
    {
        $admin = $this->admin();
        Hotel::factory()->create(['name' => 'Sparse Hotel']);

        $row = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/hotels?search=Sparse')
            ->assertOk()
            ->json('data.data.0');

        // The keys must always be present so the edit form can bind to them,
        // even when nothing has been entered yet.
        $this->assertArrayHasKey('phone_2', $row);
        $this->assertArrayHasKey('description', $row);
        $this->assertArrayHasKey('comment', $row);
        $this->assertNull($row['phone_2']);
    }
}
