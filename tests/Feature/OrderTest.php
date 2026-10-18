<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Order;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\OrderService;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Room $room;

    private Guest $guest;

    private User $actor;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        // 10% tax, restaurant enabled, spa disabled.
        $this->hotel = Hotel::factory()->create([
            'tax_rate' => 10,
            'has_restaurant' => true,
            'has_spa' => false,
            'has_leisure' => false,
        ]);

        $roomType = RoomType::factory()->create(['hotel_id' => $this->hotel->id]);
        RatePlan::create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $roomType->id,
            'name' => 'BAR',
            'currency' => 'USD',
            'base_rate_cents' => 10000,
            'basis' => 'daily',
            'is_active' => true,
        ]);
        $this->room = Room::factory()->create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $roomType->id,
        ]);
        $this->guest = Guest::factory()->create(['hotel_id' => $this->hotel->id]);
        $this->actor = User::factory()->create(['hotel_id' => $this->hotel->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->hotel->id);
        $this->actor->syncRoles([Role::query()->where('name', 'owner')->first()]);
        HotelContext::set($this->hotel->id);

        $this->booking = app(BookingService::class)->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08', // 3 nights x 10000 = 30000 + 10% tax = 33000
            'rooms' => [['room_id' => $this->room->id]],
        ]);
    }

    protected function tearDown(): void
    {
        HotelContext::clear();
        parent::tearDown();
    }

    public function test_creating_an_order_rolls_its_untaxed_total_into_the_booking(): void
    {
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/orders", [
                'category' => 'restaurant',
                'items' => [
                    ['description' => 'Dinner', 'quantity' => 2, 'unit_price_cents' => 5000],
                    ['description' => 'Water', 'quantity' => 3, 'unit_price_cents' => 1000],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'restaurant')
            ->assertJsonPath('data.total_cents', 13000)
            ->assertJsonCount(2, 'data.items');

        $booking = $this->booking->fresh();
        $this->assertSame(13000, $booking->orders_cents);
        // Orders are untaxed: 30000 + 3000 tax + 13000 = 46000.
        $this->assertSame(46000, $booking->total_cents);
    }

    public function test_voiding_an_order_restores_the_booking_total(): void
    {
        $orderId = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/orders", [
                'category' => 'restaurant',
                'items' => [['description' => 'Lunch', 'quantity' => 1, 'unit_price_cents' => 8000]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(41000, $this->booking->fresh()->total_cents);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/orders/{$orderId}/void", ['reason' => 'Mistake'])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        $booking = $this->booking->fresh();
        $this->assertSame(0, $booking->orders_cents);
        $this->assertSame(33000, $booking->total_cents);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'void']);
    }

    public function test_order_on_a_disabled_service_is_rejected(): void
    {
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/orders", [
                'category' => 'spa',
                'items' => [['description' => 'Massage', 'quantity' => 1, 'unit_price_cents' => 6000]],
            ])
            ->assertUnprocessable()
            ->assertJson(['error' => 'FEATURE_DISABLED']);

        $this->assertSame(33000, $this->booking->fresh()->total_cents);
    }

    public function test_order_requires_at_least_one_valid_item(): void
    {
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/orders", [
                'category' => 'restaurant',
                'items' => [],
            ])
            ->assertUnprocessable();
    }

    public function test_modifying_the_stay_preserves_the_orders_subtotal(): void
    {
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/orders", [
                'category' => 'restaurant',
                'items' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price_cents' => 4000]],
            ])
            ->assertCreated();

        $this->actingAs($this->actor, 'sanctum')
            ->putJson("/api/v1/bookings/{$this->booking->id}", [
                'check_in' => '2026-10-05',
                'check_out' => '2026-10-07', // 2 nights x 10000 = 20000 + 10% = 22000
            ])
            ->assertOk();

        $booking = $this->booking->fresh();
        $this->assertSame(4000, $booking->orders_cents);
        // 20000 + 2000 tax + 4000 orders = 26000.
        $this->assertSame(26000, $booking->total_cents);
    }

    public function test_voided_orders_are_excluded_from_the_total(): void
    {
        $this->order(['description' => 'A', 'quantity' => 1, 'unit_price_cents' => 1000]);
        $second = $this->order(['description' => 'B', 'quantity' => 1, 'unit_price_cents' => 2000]);

        Order::findOrFail($second)->update(['status' => 'void']);
        app(OrderService::class)->syncBooking($this->booking->fresh());

        $this->assertSame(1000, $this->booking->fresh()->orders_cents);
    }

    public function test_listing_orders_returns_items(): void
    {
        $this->order(['description' => 'Breakfast', 'quantity' => 2, 'unit_price_cents' => 1500]);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->id}/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.items');
    }

    public function test_orders_are_scoped_to_the_hotel(): void
    {
        $order = $this->order(['description' => 'A', 'quantity' => 1, 'unit_price_cents' => 1000]);

        // A user from another hotel must never reach this order, even with the
        // owner role in their own tenant.
        $otherHotel = Hotel::factory()->create();
        $other = User::factory()->create(['hotel_id' => $otherHotel->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherHotel->id);
        $other->syncRoles([Role::query()->where('name', 'owner')->first()]);

        // Drop the pinned context so the request resolves the tenant from the
        // bearer token, as it does in production.
        HotelContext::clear();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/orders/{$order}/void")
            ->assertNotFound();
    }

    private function order(array $item): int
    {
        return (int) $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/orders", [
                'category' => 'restaurant',
                'items' => [$item],
            ])
            ->assertCreated()
            ->json('data.id');
    }
}
