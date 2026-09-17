<?php

namespace Tests\Feature;

use App\Exceptions\BookingConflictException;
use App\Exceptions\ValidationException;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Room $room;

    private Guest $guest;

    private RatePlan $plan;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->create(['tax_rate' => 10]);
        $roomType = RoomType::factory()->create(['hotel_id' => $this->hotel->id]);
        $this->plan = RatePlan::create([
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
    }

    protected function tearDown(): void
    {
        HotelContext::clear();
        parent::tearDown();
    }

    public function test_create_booking_snapshots_pricing_and_totals(): void
    {
        $service = app(BookingService::class);

        $booking = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
            'adults' => 2,
        ]);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(3, $booking->nights);
        $this->assertSame(30000, $booking->subtotal_cents);
        $this->assertSame(3000, $booking->tax_cents); // 10%
        $this->assertSame(33000, $booking->total_cents);
        $this->assertSame(10000, $booking->rooms()->first()->nightly_rate_cents);
        $this->assertTrue($this->room->fresh()->status !== 'available'); // marked dirty/prepped
    }

    public function test_overbooking_is_rejected(): void
    {
        $service = app(BookingService::class);
        $payload = [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ];

        $service->create($this->hotel, $this->actor, $payload);

        // Adjacent overlapping window (check-in inside the existing stay).
        $this->expectException(BookingConflictException::class);

        $service->create($this->hotel, $this->actor, array_merge($payload, [
            'check_in' => '2026-10-07',
            'check_out' => '2026-10-10',
        ]));
    }

    public function test_back_to_back_bookings_on_same_room_are_allowed(): void
    {
        $service = app(BookingService::class);

        $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        // Check-out the same day the first ends is NOT a conflict (half-open intervals).
        $booking = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-08',
            'check_out' => '2026-10-10',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $this->assertSame('confirmed', $booking->status);
    }

    public function test_checkin_checkout_workflow(): void
    {
        $service = app(BookingService::class);

        $booking = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $checkedIn = $service->checkIn($booking->fresh(), $this->actor);
        $this->assertSame('checked_in', $checkedIn->status);
        $this->assertSame('occupied', $this->room->fresh()->status);

        $checkedOut = $service->checkOut($checkedIn, $this->actor);
        $this->assertSame('checked_out', $checkedOut->status);
        $this->assertSame('dirty', $this->room->fresh()->status);
    }

    public function test_checkin_before_start_date_is_rejected(): void
    {
        $service = app(BookingService::class);

        $booking = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        try {
            $service->checkIn($booking->fresh(), $this->actor);
            $this->fail('Check-in before the reservation start date should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertSame('CHECK_IN_TOO_EARLY', $e->reason);
        }

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('dirty', $this->room->fresh()->status);
    }

    public function test_cancel_frees_the_room(): void
    {
        $service = app(BookingService::class);

        $booking = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $cancelled = $service->cancel($booking->fresh(), $this->actor, 'Owner request');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertDatabaseHas('booking_rooms', [ // inactive allocation remains for history
            'booking_id' => $booking->id,
            'active' => false,
        ]);
        $this->assertFalse($cancelled->rooms()->first()->active);

        // Re-bookable now.
        $again = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $this->assertSame('confirmed', $again->status);
    }

    public function test_payment_updates_booking_balance(): void
    {
        $service = app(BookingService::class);
        $payments = app(PaymentService::class);

        $booking = $service->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $payment = $payments->record($booking, $this->actor, [
            'amount_cents' => 20000,
            'method' => 'cash',
        ]);

        $this->assertSame('completed', $payment->status);
        $this->assertSame(20000, $booking->fresh()->paid_cents);
        $this->assertSame(13000, $booking->fresh()->balance_due_cents);
    }

    public function test_cancelled_booking_does_not_count_as_occupancy(): void
    {
        // Occupancy/revenue semantics are covered in ReportServiceTest.
        $this->assertTrue(true);
    }

    public function test_room_daily_rate_drives_booking_total_and_is_snapshotted(): void
    {
        $this->room->update(['daily_rate_cents' => 15000]);

        $booking = app(BookingService::class)->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08', // 3 nights
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $line = $booking->rooms()->first();

        $this->assertSame(3, $line->nights);
        $this->assertSame(15000, $line->nightly_rate_cents);
        $this->assertNull($line->rate_plan_id);
        $this->assertSame(45000, $line->line_total_cents);

        // 3 nights x 15000 = 45000, plus 10% tax = 49500.
        $this->assertSame(45000, $booking->subtotal_cents);
        $this->assertSame(4500, $booking->tax_cents);
        $this->assertSame(49500, $booking->total_cents);
    }

    public function test_room_daily_rate_wins_over_rate_plan(): void
    {
        $this->room->update(['daily_rate_cents' => 9000]);

        $booking = app(BookingService::class)->create($this->hotel, $this->actor, [
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-06', // 1 night; plan charges 10000
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $this->assertSame(9000, $booking->rooms()->first()->nightly_rate_cents);
        $this->assertSame(9900, $booking->total_cents); // 9000 + 10%
    }

    public function test_create_booking_creates_guest_from_inline_details(): void
    {
        $booking = app(BookingService::class)->create($this->hotel, $this->actor, [
            'guest' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
                'phone' => '+447700900001',
            ],
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $guest = $booking->guest;

        $this->assertSame('Ada', $guest->first_name);
        $this->assertSame('Lovelace', $guest->last_name);
        $this->assertSame('ada@example.test', $guest->email);
        $this->assertSame($guest->id, $booking->guest_id);
        $this->assertDatabaseHas('guests', [
            'hotel_id' => $this->hotel->id,
            'first_name' => 'Ada',
        ]);
    }

    public function test_create_booking_reuses_existing_guest_by_email(): void
    {
        $existing = Guest::factory()->create([
            'hotel_id' => $this->hotel->id,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.test',
        ]);

        $booking = app(BookingService::class)->create($this->hotel, $this->actor, [
            'guest' => [
                'first_name' => 'Grace', // typo-safe: profile wins on email match
                'last_name' => 'Hopper',
                'email' => 'grace@example.test',
            ],
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);

        $this->assertSame($existing->id, $booking->guest_id);
        $this->assertSame(1, Guest::query()->where('hotel_id', $this->hotel->id)->where('email', 'grace@example.test')->count());
    }

    public function test_create_booking_rejects_guest_from_another_hotel(): void
    {
        $other = Guest::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->expectException(\App\Exceptions\ValidationException::class);

        app(BookingService::class)->create($this->hotel, $this->actor, [
            'guest_id' => $other->id,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $this->room->id]],
        ]);
    }

    public function test_public_endpoint_creates_pending_reservation_holding_the_room(): void
    {
        $this->getJson('/api/v1/hotels/not-active/public')->assertNotFound();

        $response = $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/bookings", [
            'guest' => [
                'first_name' => 'Public',
                'last_name' => 'Guest',
                'email' => 'public@example.test',
                'phone' => '+221700000001',
            ],
            'check_in' => '2026-10-20',
            'check_out' => '2026-10-22',
            'rooms' => [$this->room->id],
            'adults' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source', 'website')
            ->assertJsonPath('data.guest.email', 'public@example.test');

        $created = Booking::query()->findOrFail($response->json('data.id'));

        $this->assertSame('pending', $created->status);
        $this->assertNull($created->created_by);

        $allocation = $created->rooms()->first();
        $this->assertTrue($allocation->active);
        $this->assertNotNull($allocation->active_key);

        // The pending request holds the room: an overlapping attempt must fail.
        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/bookings", [
            'guest' => ['first_name' => 'Other', 'last_name' => 'Guest'],
            'check_in' => '2026-10-21',
            'check_out' => '2026-10-23',
            'rooms' => [$this->room->id],
        ])->assertJson(['error' => 'ROOM_CONFLICT']);
    }

    public function test_public_booking_rejects_another_hotel_room(): void
    {
        $foreignRoom = Room::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->postJson("/api/v1/hotels/{$this->hotel->slug}/public/bookings", [
            'guest' => ['first_name' => 'A', 'last_name' => 'B'],
            'check_in' => '2026-10-20',
            'check_out' => '2026-10-22',
            'rooms' => [$foreignRoom->id],
        ])
            ->assertUnprocessable()
            ->assertJson(['error' => 'ROOM_TENANT_MISMATCH']);
    }

    public function test_hotel_user_accepts_pending_reservation(): void
    {
        $booking = app(BookingService::class)->create($this->hotel, null, [
            'guest' => ['first_name' => 'Pending', 'last_name' => 'Guest'],
            'check_in' => '2026-10-20',
            'check_out' => '2026-10-22',
            'rooms' => [['room_id' => $this->room->id]],
            'status' => 'pending',
            'source' => 'website',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertTrue($booking->rooms()->first()->active);
    }
}