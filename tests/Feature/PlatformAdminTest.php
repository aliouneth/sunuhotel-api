<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
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

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private function pendingTenant(): array
    {
        $hotel = Hotel::factory()->create(['status' => 'pending']);
        $user = User::factory()->create(['hotel_id' => $hotel->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', 'owner')->first()]);
        HotelContext::clear();

        return [$hotel, $user];
    }

    public function test_register_creates_hotel_with_pending_status(): void
    {
        Role::seedPolicies();

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Founder',
            'email' => 'founder+new@example.org',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'hotel' => ['name' => 'New Beach Resort', 'currency' => 'USD'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.hotel.status', 'pending');
    }

    public function test_register_imports_sample_catalogue(): void
    {
        Role::seedPolicies();

        // The seeded "Sunuhotel Dakar" reference hotel.
        $sample = Hotel::create([
            'slug' => Hotel::SAMPLE_HOTEL_SLUG,
            'name' => 'Sunuhotel Dakar',
            'currency' => 'XOF',
            'status' => 'active',
        ]);
        $sample->roomTypes()->create([
            'name' => 'Deluxe',
            'description' => 'Sea view',
            'base_capacity' => 2,
            'max_capacity' => 4,
            'base_rate_cents' => 110000,
            'features' => ['wifi' => true],
            'is_active' => true,
        ]);
        $sample->expenseTypes()->create(['name' => 'Eau', 'key' => null, 'color' => 'sky', 'is_active' => true]);
        $sample->expenseTypes()->create(['name' => 'Salaire', 'key' => 'salary', 'is_active' => true]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Ousmane Sy',
            'email' => 'founder+import@example.org',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'hotel' => ['name' => 'New Port Hotel', 'currency' => 'XOF'],
        ]);

        $response->assertCreated();
        $hotelId = $response->json('user.hotel.id');

        $hotel = Hotel::findOrFail($hotelId);
        $this->assertSame(1, $hotel->roomTypes()->count());
        $this->assertSame(2, $hotel->expenseTypes()->count());
        $this->assertSame('Deluxe', $hotel->roomTypes()->first()->name);
        $this->assertSame(110000, $hotel->roomTypes()->first()->base_rate_cents);
        $this->assertTrue($hotel->expenseTypes()->where('key', 'salary')->exists());
        $this->assertDatabaseHas('expense_types', [
            'hotel_id' => $hotelId,
            'name' => 'Eau',
            'key' => null,
        ]);
    }

    public function test_register_without_sample_hotel_still_works(): void
    {
        Role::seedPolicies();

        // No reference hotel seeded -> registration must not fail.
        $this->postJson('/api/v1/register', [
            'name' => 'Marie Fall',
            'email' => 'founder+nosample@example.org',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'hotel' => ['name' => 'Quiet Inn', 'currency' => 'USD'],
        ])->assertCreated();
    }

    public function test_pending_hotel_users_are_blocked_from_tenant_routes(): void
    {
        [$hotel, $user] = $this->pendingTenant();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/rooms')
            ->assertForbidden()
            ->assertJson(['error' => 'HOTEL_NOT_ACTIVE']);
    }

    public function test_pending_hotel_is_not_publicly_visible(): void
    {
        [$hotel] = $this->pendingTenant();

        $this->getJson("/api/v1/hotels/{$hotel->slug}/public")->assertNotFound();
    }

    public function test_hotel_users_cannot_use_platform_api(): void
    {
        [$hotel, $user] = $this->pendingTenant();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/hotels')
            ->assertForbidden()
            ->assertJson(['error' => 'PLATFORM_ADMIN_REQUIRED']);
    }

    public function test_platform_admin_can_list_approve_and_unlock_a_hotel(): void
    {
        [$pendingHotel, $owner] = $this->pendingTenant();
        Hotel::factory()->create(['status' => 'active']);
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        // List: pending hotel present.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/hotels?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        // Approve.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$pendingHotel->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('hotels', ['id' => $pendingHotel->id, 'status' => 'active']);

        // Tenant now usable + publicly visible.
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/rooms')
            ->assertOk();

        $this->getJson("/api/v1/hotels/{$pendingHotel->slug}/public")->assertOk();
    }

    public function test_public_search_finds_all_active_hotels_with_word_in_name_only(): void
    {
        Hotel::create([
            'uuid' => fake()->uuid(),
            'slug' => 'sunu-dakar-1',
            'name' => 'Sunu Dakar Resort',
            'country' => 'SN',
            'city' => 'Dakar',
            'phone' => '+221111111111',
            'email' => 'a@example.org',
            'timezone' => 'UTC',
            'currency' => 'XOF',
            'tax_rate' => 18,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'status' => 'active',
        ]);
        Hotel::create([
            'uuid' => fake()->uuid(),
            'slug' => 'hotel-sunuhotel-lake',
            'name' => 'Lake Sunu Hotel',
            'country' => 'CI',
            'city' => 'Abidjan',
            'phone' => '+225222222222',
            'email' => 'b@example.org',
            'timezone' => 'UTC',
            'currency' => 'XOF',
            'tax_rate' => 18,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'status' => 'active',
        ]);
        // Active hotel that only matches on slug, not on the name.
        Hotel::create([
            'uuid' => fake()->uuid(),
            'slug' => 'sunu-no-name-match',
            'name' => 'Palm Beach Resort',
            'country' => 'SN',
            'city' => 'Saly',
            'phone' => '+221333333333',
            'email' => 'c@example.org',
            'timezone' => 'UTC',
            'currency' => 'XOF',
            'tax_rate' => 18,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'status' => 'active',
        ]);
        // Pending hotel with the word in its name: must stay hidden.
        Hotel::create([
            'uuid' => fake()->uuid(),
            'slug' => 'sunu-pending',
            'name' => 'Sunu Pending Palace',
            'country' => 'SN',
            'city' => 'Dakar',
            'phone' => '+221444444444',
            'email' => 'd@example.org',
            'timezone' => 'UTC',
            'currency' => 'XOF',
            'tax_rate' => 18,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'status' => 'pending',
        ]);

        $this->getJson('/api/v1/hotels/search?q=sunu')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Lake Sunu Hotel')
            ->assertJsonPath('data.1.name', 'Sunu Dakar Resort')
            ->assertJsonMissingPath('data.2');
    }

    public function test_search_by_city_and_dates_returns_only_hotels_with_availability(): void
    {
        $freeHotel = Hotel::factory()->create([
            'slug' => 'kaolack-oasis',
            'name' => 'Kaolack Oasis',
            'city' => 'Kaolack',
            'country' => 'SN',
            'status' => 'active',
        ]);
        $typeA = RoomType::factory()->create(['hotel_id' => $freeHotel->id]);
        Room::create([
            'hotel_id' => $freeHotel->id,
            'room_number' => 101,
            'room_type_id' => $typeA->id,
            'capacity' => 2,
            'status' => 'available',
        ]);

        $fullHotel = Hotel::factory()->create([
            'slug' => 'kaolack-plaza',
            'name' => 'Kaolack Plaza',
            'city' => 'Kaolack',
            'country' => 'SN',
            'status' => 'active',
        ]);
        $typeB = RoomType::factory()->create(['hotel_id' => $fullHotel->id]);
        $roomB = Room::create([
            'hotel_id' => $fullHotel->id,
            'room_number' => 201,
            'room_type_id' => $typeB->id,
            'capacity' => 2,
            'status' => 'available',
        ]);

        $booking = Booking::create([
            'hotel_id' => $fullHotel->id,
            'booking_number' => 'BOOK-9001',
            'guest_id' => Guest::factory()->create(['hotel_id' => $fullHotel->id])->id,
            'status' => 'confirmed',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'total_cents' => 40000,
        ]);
        BookingRoom::create([
            'hotel_id' => $fullHotel->id,
            'booking_id' => $booking->id,
            'room_id' => $roomB->id,
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'nights' => 4,
            'nightly_rate_cents' => 10000,
            'line_total_cents' => 40000,
            'active' => true,
        ]);

        $this->getJson('/api/v1/hotels/search?city=Kaolack&check_in=2026-10-02&check_out=2026-10-04')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'kaolack-oasis')
            ->assertJsonCount(1, 'data.0.available_rooms')
            ->assertJsonMissingPath('data.1');
    }

    public function test_platform_admin_can_edit_hotel_details(): void
    {
        [$pendingHotel] = $this->pendingTenant();
        $active = Hotel::factory()->create(['slug' => 'taken-slug', 'status' => 'active']);
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$pendingHotel->id}", [
                'name' => 'Renamed Resort',
                'legal_name' => 'Renamed Resort SARL',
                'city' => 'Dakar',
                'country' => 'SN',
                'currency' => 'XOF',
                'timezone' => 'Africa/Dakar',
                'locale' => 'fr',
                'status' => 'trial',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Resort')
            ->assertJsonPath('data.city', 'Dakar')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.status', 'trial');

        $this->assertDatabaseHas('hotels', [
            'id' => $pendingHotel->id,
            'name' => 'Renamed Resort',
            'legal_name' => 'Renamed Resort SARL',
            'city' => 'Dakar',
            'country' => 'SN',
            'currency' => 'XOF',
            'timezone' => 'Africa/Dakar',
            'status' => 'trial',
        ]);

        // Slug conflicts are rejected.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$pendingHotel->id}", ['slug' => 'taken-slug'])
            ->assertUnprocessable();

        // Tenant users are not allowed to edit hotels via the platform API.
        [, $owner] = $this->pendingTenant();
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$pendingHotel->id}", ['name' => 'Hacker'])
            ->assertForbidden()
            ->assertJson(['error' => 'PLATFORM_ADMIN_REQUIRED']);
    }

    public function test_platform_admin_can_upload_hotel_logo(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'pending']);
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/platform/hotels/{$hotel->id}", [
                '_method' => 'PUT',
                'name' => 'Logo Resort',
                'logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 195, 140),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Logo Resort')
            ->assertJsonPath('data.logo_url', 'http://localhost:8000/uploads/hotels/'.$hotel->id.'/logo.png');

        $this->assertDatabaseHas('hotels', [
            'id' => $hotel->id,
            'logo_path' => '/uploads/hotels/'.$hotel->id.'/logo.png',
        ]);
        $this->assertFileExists(public_path('uploads/hotels/'.$hotel->id.'/logo.png'));
    }

    public function test_tenant_can_update_hotel_details_and_logo(): void
    {
        Role::seedPolicies();
        [$hotel, $owner] = $this->pendingTenant();
        $hotel->update(['status' => 'active']);

        $logo = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 195, 140);

        $this->actingAs($owner, 'sanctum')
            ->post('/api/v1/hotel', [
                '_method' => 'PUT',
                'name' => 'Renamed Tenant',
                'city' => 'Saly',
                'currency' => 'XOF',
                'tax_rate' => 5,
                'logo' => $logo,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Tenant')
            ->assertJsonPath('data.city', 'Saly')
            ->assertJsonPath('data.tax_rate', 5)
            ->assertJsonPath('data.logo_url', 'http://localhost:8000/uploads/hotels/'.$hotel->id.'/logo.png');

        $this->assertDatabaseHas('hotels', [
            'id' => $hotel->id,
            'name' => 'Renamed Tenant',
            'city' => 'Saly',
            'tax_rate' => 5,
        ]);

        $this->assertFileExists(public_path('uploads/hotels/'.$hotel->id.'/logo.png'));
    }

    public function test_platform_admin_can_manage_rooms_of_a_hotel(): void
    {
        [$hotel] = $this->pendingTenant();
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        // Seed a room type owned by the hotel.
        $roomType = \App\Models\RoomType::factory()->create(['hotel_id' => $hotel->id]);
        \App\Models\Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'room_number' => '101']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/platform/hotels/{$hotel->id}/room-types")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // List rooms.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/platform/hotels/{$hotel->id}/rooms")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_number', '101');

        // Create a room.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$hotel->id}/rooms", [
                'room_number' => '201',
                'floor' => 2,
                'room_type_id' => $roomType->id,
                'capacity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.room_number', '201');

        $roomId = $hotel->rooms()->where('room_number', '201')->value('id');

        // Update.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$hotel->id}/rooms/{$roomId}", [
                'room_number' => '202',
                'status' => 'maintenance',
                'notes' => 'Needs new AC',
            ])
            ->assertOk()
            ->assertJsonPath('data.room_number', '202')
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('data.notes', 'Needs new AC');

        // Rooms of another hotel cannot be touched via this hotel's scope.
        $otherRoom = \App\Models\Room::factory()->create();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/platform/hotels/{$hotel->id}/rooms/{$otherRoom->id}", ['room_number' => '999'])
            ->assertNotFound();

        // Delete frees the room.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/platform/hotels/{$hotel->id}/rooms/{$roomId}")
            ->assertOk();

        $this->assertSoftDeleted('rooms', ['id' => $roomId]);
    }

    public function test_platform_admin_can_import_room_types_from_another_hotel(): void
    {
        $target = Hotel::factory()->create(['status' => 'active']);
        $source = Hotel::factory()->create(['status' => 'active']);
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        $source->roomTypes()->create(['name' => 'Deluxe', 'base_capacity' => 2, 'max_capacity' => 4, 'base_rate_cents' => 110000, 'is_active' => true]);
        $source->roomTypes()->create(['name' => 'Standard', 'base_capacity' => 1, 'max_capacity' => 2, 'base_rate_cents' => 50000, 'is_active' => true]);
        $target->roomTypes()->create(['name' => 'Deluxe', 'base_capacity' => 2, 'max_capacity' => 3, 'base_rate_cents' => 90000, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$target->id}/room-types/import", ['source_hotel_id' => $source->id])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(2, $target->roomTypes()->count());
        $this->assertDatabaseHas('room_types', [
            'hotel_id' => $target->id,
            'name' => 'Standard',
            'base_rate_cents' => 50000,
        ]);

        // Soft-deleted names must not block a re-import.
        $target->roomTypes()->where('name', 'Standard')->delete();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$target->id}/room-types/import", ['source_hotel_id' => $source->id])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(2, $target->roomTypes()->count());

        // Importing from the target itself is rejected.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$target->id}/room-types/import", ['source_hotel_id' => $target->id])
            ->assertStatus(422);
    }

    public function test_reject_keeps_hotel_blocked_and_blocking_message_contextual(): void
    {
        [$pendingHotel] = $this->pendingTenant();
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$pendingHotel->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $owner = $pendingHotel->users()->first();
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/hotel')
            ->assertForbidden()
            ->assertJson(['error' => 'HOTEL_NOT_ACTIVE']);
    }

    public function test_support_endpoint_is_public_and_corporate_settings_editable(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // Public defaults (no auth).
        $this->getJson('/api/v1/platform/support')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Sunuhotel');

        // Admin updates corporate info.
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/settings', [
                'company_name' => 'Sunuhotel HQ',
                'phone' => '+221 33 111 22 33',
                'email' => 'hello@sunuhotel.example',
                'website' => 'https://sunuhotel.example',
            ])
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Sunuhotel HQ')
            ->assertJsonPath('data.phone', '+221 33 111 22 33');

        // Public endpoint reflects the change.
        HotelContext::clear();
        $this->getJson('/api/v1/platform/support')
            ->assertJsonPath('data.phone', '+221 33 111 22 33');
    }
}