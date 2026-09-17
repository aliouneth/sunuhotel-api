<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reporting & analytics for a hotel.
 *
 * All money in integer cents. Metrics follow the hotel-KPI definitions:
 *   Occupancy %   = sold room nights / available room nights
 *   ADR (Average Daily Rate) = room revenue / sold room nights
 *   RevPAR        = room revenue / available room nights
 *
 * A "sold room night" is any active allocation date inside the window
 * (confirmed, checked_in, checked_out) — cancelled/no-show excluded.
 */
final class ReportService
{
    public function kpis(Hotel $hotel, string $from, string $to): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();

        $totalRooms = max(1, $hotel->rooms()->count());
        $availableRoomNights = (int) $from->diffInDays($to) * $totalRooms;

        $lines = $this->occupancyLines($hotel->id, $from, $to);

        $soldRoomNights = (int) $lines->sum('room_nights');
        $roomRevenue = (int) $lines->sum('revenue_cents');

        $occupancy = $availableRoomNights > 0
            ? round(($soldRoomNights / $availableRoomNights) * 100, 2)
            : 0.0;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'nights' => (int) $from->diffInDays($to),
            'total_rooms' => (int) $hotel->rooms()->count(),
            'sold_room_nights' => $soldRoomNights,
            'available_room_nights' => $availableRoomNights,
            'occupancy_percent' => $occupancy,
            'room_revenue_cents' => $roomRevenue,
            'adr_cents' => $soldRoomNights > 0 ? (int) round($roomRevenue / $soldRoomNights) : 0,
            'revpar_cents' => $availableRoomNights > 0 ? (int) round($roomRevenue / $availableRoomNights) : 0,
            'bookings_count' => $this->bookingsCount($hotel->id, $from, $to),
        ];
    }

    public function occupancyOverview(Hotel $hotel, string $from, string $to): Collection
    {
        $from = CarbonImmutable::parse($from);
        $to = CarbonImmutable::parse($to);

        $totalRooms = max(1, $hotel->rooms()->count());
        $lines = $this->occupancyLines($hotel->id, $from, $to);

        $perDay = $lines->groupBy('night');

        $days = collect();
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $key = $cursor->toDateString();
            $day = $perDay->get($key, collect());
            $sold = (int) $day->sum('room_nights');
            $days->push([
                'date' => $key,
                'sold_room_nights' => $sold,
                'occupancy_percent' => round(($sold / $totalRooms) * 100, 2),
            ]);
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Occupancy line rows: one per night with room x revenue.
     * Revenue is attributed to the DAY THE NIGHT IS SOLD (check_in night),
     * which is standard RevPAR accounting for stays within the window.
     */
    public function occupancyLines(int $hotelId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $rows = \DB::table('booking_rooms as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->where('br.hotel_id', $hotelId)
            ->where('br.active', true)
            ->whereIn('b.status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('br.check_in', '<', $to->toDateString())
            ->whereDate('br.check_out', '>', $from->toDateString())
            ->get(['br.check_in', 'br.nights', 'br.line_total_cents']);

        $lines = collect();

        foreach ($rows as $row) {
            $start = CarbonImmutable::parse($row->check_in)->max($from);
            $stayEnd = CarbonImmutable::parse($row->check_in)->addDays($row->nights)->min($to);

            $nightsInWindow = $start->diffInDays($stayEnd);
            $proratedRevenue = (int) round(
                $row->line_total_cents * ($nightsInWindow / max(1, $row->nights))
            );

            for ($i = 0; $i < $nightsInWindow; $i++) {
                $night = $start->addDays($i)->toDateString();
                $lines->push([
                    'night' => $night,
                    'room_nights' => 1,
                    'revenue_cents' => (int) round($proratedRevenue / max(1, $nightsInWindow)),
                ]);
            }
        }

        return $lines;
    }

    private function bookingsCount(int $hotelId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Booking::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->where(function ($q) use ($from, $to) {
                $q->whereDate('check_in', '<', $to->toDateString())
                    ->whereDate('check_out', '>', $from->toDateString());
            })
            ->count();
    }

    /**
     * Revenue grouped by month for trend charts.
     */
    public function revenueTrend(Hotel $hotel, string $from, string $to): Collection
    {
        $from = CarbonImmutable::parse($from)->startOfMonth();
        $to = CarbonImmutable::parse($to)->startOfMonth();

        $lines = $this->occupancyLines($hotel->id, $from, $to->endOfMonth());

        return $lines->groupBy(fn ($l) => substr($l['night'], 0, 7))
            ->map(function (Collection $monthLines) {
                return [
                    'month' => $monthLines->first()['night'],
                    'revenue_cents' => (int) $monthLines->sum('revenue_cents'),
                    'room_nights' => (int) $monthLines->sum('room_nights'),
                ];
            })
            ->values();
    }
}