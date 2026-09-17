<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ReportService;
use Illuminate\Http\Request;

/**
 * Lightweight aggregate for the dashboard.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function summary(Request $request)
    {
        $hotel = $request->user()->hotel;
        $today = now()->toDateString();

        $arrivals = Booking::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereDate('check_in', $today)
            ->count();

        $departures = Booking::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', 'checked_in')
            ->whereDate('check_out', $today)
            ->count();

        $inHouse = Booking::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', 'checked_in')
            ->count();

        $pipeline = Booking::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->sum('total_cents');

        $funnel = Booking::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('check_in', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('check_in', '<=', now()->endOfMonth()->toDateString())
            ->sum('total_cents');

        $occupancy = $this->reports->occupancyOverview($hotel, $today, now()->addDays(7)->toDateString());

        return response()->json([
            'data' => [
                'today_arrivals' => $arrivals,
                'today_departures' => $departures,
                'in_house' => $inHouse,
                'open_bookings_total_cents' => $pipeline,
                'current_month_value_cents' => $funnel,
                'occupancy_next_7_days' => $occupancy,
                'currency' => $hotel->currency,
            ],
        ]);
    }
}