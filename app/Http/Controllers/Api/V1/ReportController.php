<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function kpis(Request $request)
    {
        $this->authorize('reports.view');

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->reports->kpis($request->user()->hotel, $validated['from'], $validated['to']),
        ]);
    }

    public function occupancy(Request $request)
    {
        $this->authorize('reports.view');

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->reports->occupancyOverview($request->user()->hotel, $validated['from'], $validated['to']),
        ]);
    }

    public function revenue(Request $request)
    {
        $this->authorize('reports.view');

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->reports->revenueTrend($request->user()->hotel, $validated['from'], $validated['to']),
        ]);
    }

    /**
     * Downloadable report (CSV or JSON). Accepts format=json,file=revenue|kpis|occupancy|bookings.
     */
    public function export(Request $request)
    {
        $this->authorize('reports.view');

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['sometimes', 'in:json,csv'],
            'file' => ['required', 'in:kpis,occupancy,revenue,bookings'],
        ]);

        /** @var Hotel $hotel */
        $hotel = $request->user()->hotel;

        $data = match ($validated['file']) {
            'kpis' => [$this->reports->kpis($hotel, $validated['from'], $validated['to'])],
            'occupancy' => $this->reports->occupancyOverview($hotel, $validated['from'], $validated['to'])->all(),
            'revenue' => $this->reports->revenueTrend($hotel, $validated['from'], $validated['to'])->all(),
            'bookings' => \App\Models\Booking::query()
                ->with('guest:id,first_name,last_name')
                ->whereDate('check_in', '>=', $validated['from'])
                ->whereDate('check_in', '<=', $validated['to'])
                ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
                ->get()
                ->map(fn ($b) => [
                    'booking_number' => $b->booking_number,
                    'guest' => $b->guest->full_name,
                    'status' => $b->status,
                    'check_in' => $b->check_in->toDateString(),
                    'check_out' => $b->check_out->toDateString(),
                    'total_cents' => $b->total_cents,
                    'paid_cents' => $b->paid_cents,
                ])->all(),
        };

        AuditLogger::critical($hotel, 'report.exported', [
            'file' => $validated['file'],
            'format' => $validated['format'] ?? 'json',
        ]);

        $format = $validated['format'] ?? 'json';

        if ($format === 'csv') {
            $filename = sprintf('sunuhotel-%s-%s-to-%s.csv', $validated['file'], $validated['from'], $validated['to']);
            $columns = ! empty($data) ? array_keys((array) reset($data)) : [];

            return new StreamedResponse(function () use ($data, $columns, $filename) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $columns);

                foreach ($data as $row) {
                    fputcsv($handle, array_values((array) $row));
                }

                fclose($handle);
            }, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return response()->json(['data' => $data]);
    }
}