<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingIntent;
use App\Models\Hotel;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayDunya online payment of a public (guest) reservation.
 *
 * Payment guarantees the reservation: the public booking endpoint creates a
 * BookingIntent (no Booking), and the real, confirmed Booking is created only
 * when the IPN is verified. All outbound HTTP is faked.
 */
class PayDunyaBookingTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_KEY = 'test-master-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paydunya', [
            'mode' => 'test',
            'master_key' => self::MASTER_KEY,
            'public_key' => 'test-public-key',
            'private_key' => 'test-private-key',
            'token' => 'test-token',
            'store_name' => 'SunuHotel',
        ]);

        config()->set('app.frontend_url', 'https://app.sunuhotel.test');
    }

    private function hash(?string $key = null): string
    {
        return hash('sha512', $key ?? self::MASTER_KEY);
    }

    /**
     * @return array{0: Hotel, 1: Room}
     */
    private function hotelWithRoom(int $dailyRateCents = 10000): array
    {
        $hotel = Hotel::factory()->create(['status' => 'active', 'tax_rate' => 0]);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => 'available',
            'daily_rate_cents' => $dailyRateCents,
        ]);

        return [$hotel, $room];
    }

    private function bookingPayload(Room $room): array
    {
        return [
            'guest' => [
                'first_name' => 'Awa',
                'last_name' => 'Ndiaye',
                'email' => 'awa@example.com',
                'phone' => '+221771234567',
            ],
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
        ];
    }

    private function fakeCheckout(string $token = 'test_TOKEN123'): void
    {
        Http::fake([
            'app.paydunya.com/*/checkout-invoice/create' => Http::response([
                'response_code' => '00',
                'response_text' => "https://app.paydunya.com/sandbox-checkout/invoice/{$token}",
                'description' => 'Checkout Invoice Created',
                'token' => $token,
            ]),
        ]);
    }

    private function fakeConfirm(array $overrides = []): void
    {
        Http::fake([
            'app.paydunya.com/*/checkout-invoice/confirm/*' => Http::response(array_merge([
                'response_code' => '00',
                'response_text' => 'Transaction Found',
                'hash' => $this->hash(),
                'status' => 'completed',
                'invoice' => ['token' => 'test_TOKEN123', 'total_amount' => 200],
                'custom_data' => [],
            ], $overrides)),
        ]);
    }

    private function ipnPayload(string $token = 'test_TOKEN123', ?string $hash = null, int $intentId = 0): array
    {
        return [
            'data' => [
                'hash' => $hash ?? $this->hash(),
                'status' => 'completed',
                'invoice' => ['token' => $token],
                'custom_data' => ['intent_id' => $intentId],
            ],
        ];
    }

    /* ---------------------------- Reservation ---------------------------- */

    public function test_reservation_creates_a_paydunya_intent_and_no_booking(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $this->fakeCheckout();

        $this->postJson("/api/v1/hotels/{$hotel->slug}/public/bookings", $this->bookingPayload($room))
            ->assertStatus(201)
            ->assertJsonPath('data.token', 'test_TOKEN123')
            ->assertJsonPath('data.payment_url', 'https://app.paydunya.com/sandbox-checkout/invoice/test_TOKEN123')
            ->assertJsonPath('data.amount_cents', 20000)
            ->assertJsonPath('data.currency', 'USD');

        // No reservation exists until the payment is confirmed.
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_intents', 1);

        $intent = BookingIntent::query()->first();
        $this->assertSame(BookingIntent::STATUS_PENDING, $intent->status);
        $this->assertSame('test_TOKEN123', $intent->token);
        $this->assertSame([$room->id], $intent->rooms);
        $this->assertSame(20000, $intent->total_cents);

        Http::assertSent(function (Request $request) use ($hotel) {
            return str_contains($request->url(), '/checkout-invoice/create')
                && $request['invoice']['total_amount'] === 200            // 20000 cents -> 200 USD
                && $request['actions']['callback_url'] === url('/api/v1/paydunya/booking-ipn')
                && $request['actions']['return_url'] === 'https://app.sunuhotel.test/guest/'.$hotel->slug.'/thank-you'
                && $request['custom_data']['type'] === 'booking'
                && $request['custom_data']['hotel_id'] === $hotel->id;
        });
    }

    public function test_reservation_is_rejected_when_the_room_is_taken(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $this->fakeCheckout();

        // Occupy the room for an overlapping window.
        app(BookingService::class)->create($hotel, null, [
            'guest' => ['first_name' => 'Existing', 'last_name' => 'Guest', 'phone' => '+221770000000'],
            'check_in' => '2026-10-06',
            'check_out' => '2026-10-08',
            'rooms' => [['room_id' => $room->id]],
            'status' => 'confirmed',
        ]);

        $this->postJson("/api/v1/hotels/{$hotel->slug}/public/bookings", $this->bookingPayload($room))
            ->assertStatus(409);

        $this->assertDatabaseCount('booking_intents', 0);
        Http::assertNothingSent();
    }

    public function test_reservation_returns_503_when_the_gateway_is_not_configured(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        config()->set('services.paydunya.master_key', null);

        $this->postJson("/api/v1/hotels/{$hotel->slug}/public/bookings", $this->bookingPayload($room))
            ->assertStatus(503);

        $this->assertDatabaseCount('booking_intents', 0);
    }

    /* -------------------------------- IPN -------------------------------- */

    public function test_ipn_creates_a_confirmed_booking_and_records_the_payment(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_email' => 'awa@example.com',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'adults' => 1,
            'children' => 0,
            'subtotal_cents' => 20000,
            'tax_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 20000,
            'currency' => 'USD',
            'source' => 'website',
        ]);

        $this->fakeConfirm(['custom_data' => ['intent_id' => $intent->id]]);

        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('test_TOKEN123', null, $intent->id))
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $booking = Booking::query()->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame($hotel->id, $booking->hotel_id);
        $this->assertSame(20000, $booking->total_cents);
        $this->assertSame(20000, $booking->paid_cents);
        $this->assertSame([$room->id], $booking->rooms()->pluck('room_id')->all());

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'amount_cents' => 20000,
            'method' => 'mobile_money',
            'status' => 'completed',
            'type' => 'payment',
            'reference' => 'test_TOKEN123',
        ]);

        $fresh = $intent->fresh();
        $this->assertSame(BookingIntent::STATUS_PAID, $fresh->status);
        $this->assertSame($booking->id, $fresh->booking_id);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_ipn_rejects_a_bad_hash_without_creating_a_booking(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'total_cents' => 20000,
            'currency' => 'USD',
        ]);

        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('test_TOKEN123', 'deadbeef', $intent->id))
            ->assertForbidden();

        $this->assertDatabaseCount('bookings', 0);
        Http::assertNothingSent();
    }

    public function test_ipn_ignores_a_non_completed_status(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'total_cents' => 20000,
            'currency' => 'USD',
        ]);

        $this->fakeConfirm(['status' => 'pending', 'custom_data' => ['intent_id' => $intent->id]]);

        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('test_TOKEN123', null, $intent->id))
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(BookingIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_ipn_rejects_an_underpayment(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'total_cents' => 20000,
            'currency' => 'USD',
        ]);

        $this->fakeConfirm([
            'invoice' => ['token' => 'test_TOKEN123', 'total_amount' => 1],
            'custom_data' => ['intent_id' => $intent->id],
        ]);

        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('test_TOKEN123', null, $intent->id))
            ->assertStatus(409);

        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(BookingIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_ipn_is_idempotent_on_an_already_paid_intent(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'total_cents' => 20000,
            'currency' => 'USD',
        ]);

        $this->fakeConfirm(['custom_data' => ['intent_id' => $intent->id]]);
        $payload = $this->ipnPayload('test_TOKEN123', null, $intent->id);

        $this->post('/api/v1/paydunya/booking-ipn', $payload)->assertOk()->assertJsonPath('status', 'paid');
        $this->post('/api/v1/paydunya/booking-ipn', $payload)->assertOk()->assertJsonPath('status', 'already_paid');

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_ipn_for_an_unknown_token_is_rejected(): void
    {
        $this->fakeConfirm();

        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('nope_TOKEN'))
            ->assertNotFound();

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_ipn_with_a_malformed_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/paydunya/booking-ipn', ['not_data' => true])->assertStatus(400);
    }

    public function test_ipn_marks_the_intent_failed_when_rooms_were_taken_after_checkout(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'total_cents' => 20000,
            'currency' => 'USD',
        ]);

        // Someone else books the room while the guest is on PayDunya's page.
        app(BookingService::class)->create($hotel, null, [
            'guest' => ['first_name' => 'Other', 'last_name' => 'Guest', 'phone' => '+221770000001'],
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [['room_id' => $room->id]],
            'status' => 'confirmed',
        ]);

        $this->fakeConfirm(['custom_data' => ['intent_id' => $intent->id]]);

        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('test_TOKEN123', null, $intent->id))
            ->assertStatus(409)
            ->assertJsonPath('status', 'unavailable');

        // Only the other guest's booking exists; the paid intent is flagged.
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(BookingIntent::STATUS_FAILED, $intent->fresh()->status);
    }

    /* ------------------------------- Status ------------------------------ */

    public function test_status_endpoint_reports_the_booking_number_once_paid(): void
    {
        [$hotel, $room] = $this->hotelWithRoom();
        $intent = BookingIntent::create([
            'hotel_id' => $hotel->id,
            'status' => BookingIntent::STATUS_PENDING,
            'token' => 'test_TOKEN123',
            'guest_first_name' => 'Awa',
            'guest_last_name' => 'Ndiaye',
            'guest_phone' => '+221771234567',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
            'rooms' => [$room->id],
            'total_cents' => 20000,
            'currency' => 'USD',
        ]);

        $this->fakeConfirm(['custom_data' => ['intent_id' => $intent->id]]);
        $this->post('/api/v1/paydunya/booking-ipn', $this->ipnPayload('test_TOKEN123', null, $intent->id))->assertOk();

        $bookingNumber = Booking::query()->firstOrFail()->booking_number;

        $this->getJson('/api/v1/paydunya/booking-status/test_TOKEN123')
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.booking_number', $bookingNumber)
            ->assertJsonPath('data.amount_cents', 20000);
    }

    public function test_status_endpoint_404s_for_an_unknown_token(): void
    {
        $this->getJson('/api/v1/paydunya/booking-status/nope')->assertNotFound();
    }
}
