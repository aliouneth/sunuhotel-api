<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\MonthlyInvoice;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;
    private Hotel $hotel;
    private RoomType $roomType;
    private MonthlyInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Role::seedPolicies();
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Standard Room']);
        $platform = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        // Wrap this month: create an unpaid invoice the fee can attach to.
        $invoice = MonthlyInvoice::factory()->create([
            'hotel_id' => $hotel->id,
            'billing_month' => now()->format('Y-m'),
            'amount_cents' => 1000,
            'status' => MonthlyInvoice::STATUS_PENDING,
        ]);

        $this->platform = $platform;
        $this->hotel = $hotel;
        $this->roomType = $roomType;
        $this->ratePlan = \App\Models\RatePlan::create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'name' => 'Giga Plan',
            'currency' => 'XOF',
            'base_rate_cents' => 50000,
            'basis' => 'daily',
        ]);
        $this->invoice = $invoice;
    }

    private function promotionsPayload(array $overrides = []): array
    {
        return array_merge([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'title' => 'Weekend',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
            'original_rate_cents' => 60000,
            'promo_rate_cents' => 45000,
            'currency' => 'XOF',
        ], $overrides);
    }

    public function test_public_endpoint_only_lists_running_promotions(): void
    {
        Promotion::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        // Future window → not listed.
        Promotion::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'starts_on' => now()->addDays(5)->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(),
        ]);

        // Inactive → not listed.
        Promotion::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'is_active' => false,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/hotels/promotions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['hotel' => ['id', 'slug', 'city', 'rating', 'image_url']]]])
            ->assertJsonPath('data.0.room_type.id', $this->roomType->id);
    }

    public function test_public_endpoint_skips_pending_hotels(): void
    {
        $pending = Hotel::factory()->create(['status' => 'pending']);
        Promotion::factory()->create([
            'hotel_id' => $pending->id,
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $pending->id])->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        $this->getJson('/api/v1/hotels/promotions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_platform_creates_promotion_and_bills_current_invoice(): void
    {
        $payload = $this->promotionsPayload(['fee_cents' => 10000]);

        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/platform/promotions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.fee_cents', 10000)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('promotions', [
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'promo_rate_cents' => 45000,
        ]);

        $invoice = $this->invoice->fresh();
        $this->assertSame(11000, $invoice->amount_cents);
        $this->assertSame(MonthlyInvoice::STATUS_PENDING, $invoice->status);
    }

    public function test_creating_promotion_opens_an_already_paid_invoice(): void
    {
        $this->invoice->update([
            'status' => MonthlyInvoice::STATUS_PAID,
            'paid_at' => now(),
            'amount_cents' => 1000,
        ]);

        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/platform/promotions', $this->promotionsPayload(['fee_cents' => 5000]))
            ->assertCreated();

        $invoice = $this->invoice->fresh();
        $this->assertSame(6000, $invoice->amount_cents);
        $this->assertSame(MonthlyInvoice::STATUS_PENDING, $invoice->status);
        $this->assertNull($invoice->paid_at);
    }

    public function test_future_promotion_does_not_bill(): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/platform/promotions', $this->promotionsPayload([
                'fee_cents' => 9000,
                'starts_on' => now()->addDays(5)->toDateString(),
                'ends_on' => now()->addDays(10)->toDateString(),
            ]))
            ->assertCreated();

        $this->assertSame(1000, $this->invoice->fresh()->amount_cents);
    }

    public function test_platform_cannot_assign_room_type_of_another_hotel(): void
    {
        $otherHotel = Hotel::factory()->create();
        $otherRoomType = RoomType::factory()->create(['hotel_id' => $otherHotel->id]);

        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/platform/promotions', $this->promotionsPayload(['room_type_id' => $otherRoomType->id]))
            ->assertStatus(422);
    }

    public function test_public_hotel_profile_uses_promo_rate_for_room_type(): void
    {
        Promotion::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'original_rate_cents' => 60000,
            'promo_rate_cents' => 45000,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        $room = \App\Models\Room::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'daily_rate_cents' => 60000,
        ]);

        $this->getJson("/api/v1/hotels/{$this->hotel->slug}/public")
            ->assertOk()
            ->assertJsonPath('data.room_types.0.nightly_rate_cents', 45000)
            ->assertJsonPath('data.room_types.0.original_rate_cents', 60000)
            ->assertJsonPath('data.room_types.0.promo_rate_cents', 45000);
    }

    public function test_public_availability_uses_promo_rate_for_room_type(): void
    {
        Promotion::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'original_rate_cents' => 70000,
            'promo_rate_cents' => 50000,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        $room = \App\Models\Room::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'daily_rate_cents' => 70000,
        ]);

        $this->getJson("/api/v1/hotels/{$this->hotel->slug}/public/availability?check_in=".now()->addDays(1)->toDateString().'&check_out='.now()->addDays(3)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.rooms')
            ->assertJsonPath('data.rooms.0.rate_cents', 50000)
            ->assertJsonPath('data.rooms.0.original_rate_cents', 70000)
            ->assertJsonPath('data.rooms.0.promo_rate_cents', 50000);

        $this->assertSame($room->id, \App\Models\Room::find($room->id)->id);
    }

    public function test_website_booking_is_priced_at_promo_rate(): void
    {
        Promotion::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'original_rate_cents' => 80000,
            'promo_rate_cents' => 60000,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        $room = \App\Models\Room::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'daily_rate_cents' => 80000,
        ]);

        $response = $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/bookings", [
            'guest' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'phone' => '33445566',
            ],
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'rooms' => [$room->id],
            'adults' => 1,
        ]);

        // 2 nights x 60000 promo rate, subtotal 120000; hotel tax 8% → total 129600.
        $response->assertCreated();
        $booking = $response->json('data');

        $this->assertSame(120000, $booking['subtotal_cents']);
        $this->assertSame(129600, $booking['total_cents']);
    }

    public function test_non_platform_user_cannot_manage_promotions(): void
    {
        $manager = User::factory()->create(['hotel_id' => $this->hotel->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->hotel->id);
        $manager->syncRoles([Role::query()->where('name', 'manager')->first()]);
        HotelContext::clear();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/platform/promotions')
            ->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/platform/promotions', $this->promotionsPayload())
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Country tax rate fallback + platform CRUD                           */
    /* ------------------------------------------------------------------ */

    private function countryTaxPayload(array $overrides = []): array
    {
        return array_merge([
            'country_code' => 'SN',
            'tax_rate' => 18,
        ], $overrides);
    }

    public function test_country_rate_is_pure_fallback_after_hotel_tax(): void
    {
        $this->hotel->update(['tax_rate' => 0, 'country' => 'SN']);
        \App\Models\CountryTaxRate::create([
            'country_code' => 'SN',
            'tax_rate' => 18,
        ]);

        $room = \App\Models\Room::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'daily_rate_cents' => 50000,
        ]);

        // Hotel tax 0 + country SN @18 → 2 nights x 50000 subtotal, tax 18000,
        // total 118000; the public booking carries the applied fallback tax.
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/bookings", [
            'guest' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '33445566'],
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'rooms' => [$room->id],
            'adults' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal_cents', 100000)
            ->assertJsonPath('data.tax_cents', 18000)
            ->assertJsonPath('data.total_cents', 118000);
    }

    public function test_hotel_own_tax_rate_still_wins_over_country(): void
    {
        $this->hotel->update(['tax_rate' => 10]);
        \App\Models\CountryTaxRate::create([
            'country_code' => 'SN',
            'tax_rate' => 18,
        ]);

        $this->assertSame(
            10.0,
            \App\Models\CountryTaxRate::effectiveRateForHotel($this->hotel)
        );
    }

    public function test_platform_can_crud_country_tax_rates(): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/platform/country-tax-rates', $this->countryTaxPayload(['country_code' => 'CI', 'tax_rate' => 20]))
            ->assertCreated()
            ->assertJsonPath('data.country_code', 'CI');

        $id = \App\Models\CountryTaxRate::query()->where('country_code', 'CI')->first()->id;

        $this->actingAs($this->platform, 'sanctum')
            ->putJson("/api/v1/platform/country-tax-rates/{$id}", ['tax_rate' => 20.5])
            ->assertOk()
            ->assertJsonPath('data.tax_rate', 20.5);

        $this->actingAs($this->platform, 'sanctum')
            ->getJson('/api/v1/platform/country-tax-rates')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->platform, 'sanctum')
            ->deleteJson("/api/v1/platform/country-tax-rates/{$id}")
            ->assertNoContent();
    }

    public function test_non_platform_user_cannot_manage_country_tax_rates(): void
    {
        $manager = User::factory()->create(['hotel_id' => $this->hotel->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->hotel->id);
        $manager->syncRoles([Role::query()->where('name', 'manager')->first()]);
        HotelContext::clear();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/platform/country-tax-rates')
            ->assertForbidden();
    }
}