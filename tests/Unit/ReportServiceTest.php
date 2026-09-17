<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpis_compute_occupancy_adr_revpar(): void
    {
        $hotel = Hotel::factory()->create(['tax_rate' => 0]);
        $type = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id]);
        $guest = Guest::factory()->create(['hotel_id' => $hotel->id]);

        // 3-night confirmed stay at 10000/night starting 3 days ago.
        $from = Carbon::today()->subDays(3);
        $booking = Booking::create([
            'hotel_id' => $hotel->id,
            'booking_number' => 'TEST-0001',
            'guest_id' => $guest->id,
            'status' => 'checked_out',
            'check_in' => $from,
            'check_out' => $from->copy()->addDays(3),
            'adults' => 1, 'children' => 0,
            'subtotal_cents' => 30000, 'tax_cents' => 0, 'total_cents' => 30000, 'paid_cents' => 30000,
        ]);

        BookingRoom::create([
            'hotel_id' => $hotel->id,
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'check_in' => $from,
            'check_out' => $from->copy()->addDays(3),
            'nights' => 3,
            'nightly_rate_cents' => 10000,
            'line_total_cents' => 30000,
            'active' => true,
        ]);

        $service = app(ReportService::class);

        $kpis = $service->kpis($hotel, today()->subDays(10)->toDateString(), today()->addDays(1)->toDateString());

        $this->assertSame(3, $kpis['sold_room_nights']);
        $this->assertSame(11, $kpis['available_room_nights']); // 11 days x 1 room
        $this->assertSame(30000, $kpis['room_revenue_cents']);
        $this->assertSame(10000, $kpis['adr_cents']);
        $this->assertSame((int) round(30000 / 11), $kpis['revpar_cents']);
        $this->assertSame(round((3 / 11) * 100, 2), $kpis['occupancy_percent']);
    }

    public function test_cancelled_rooms_are_excluded_from_metrics(): void
    {
        $hotel = Hotel::factory()->create();
        $type = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $room = Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id]);
        $guest = Guest::factory()->create(['hotel_id' => $hotel->id]);

        $from = Carbon::today()->subDays(2);
        $booking = Booking::create([
            'hotel_id' => $hotel->id,
            'booking_number' => 'TEST-0002',
            'guest_id' => $guest->id,
            'status' => 'cancelled',
            'check_in' => $from,
            'check_out' => $from->copy()->addDays(2),
            'adults' => 1, 'children' => 0,
            'subtotal_cents' => 20000, 'tax_cents' => 0, 'total_cents' => 20000, 'paid_cents' => 0,
        ]);
        BookingRoom::create([
            'hotel_id' => $hotel->id,
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'check_in' => $from,
            'check_out' => $from->copy()->addDays(2),
            'nights' => 2,
            'nightly_rate_cents' => 10000,
            'line_total_cents' => 20000,
            'active' => false,
        ]);

        $kpis = app(ReportService::class)->kpis($hotel, today()->subDays(5)->toDateString(), today()->toDateString());

        $this->assertSame(0, $kpis['sold_room_nights']);
        $this->assertSame(0, $kpis['room_revenue_cents']);
    }
}