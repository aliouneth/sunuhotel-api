<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-party audience measurement for the public Sunuhotel site.
 *
 * `collect` is called by a small beacon on public pages after the visitor has
 * accepted analytics. `summary` powers the platform administrator's audience
 * dashboard. Only aggregate, anonymous counts are exposed.
 */
class AnalyticsController extends Controller
{
    /** Private or staff-only areas that must never count as public audience. */
    private const IGNORED_PREFIXES = [
        '/dashboard',
        '/platform',
        '/login',
        '/register',
        '/pending',
        '/my-reservations',
        '/api',
        '/_next',
        '/favicon',
        '/robots.txt',
        '/sitemap.xml',
    ];

    public function collect(Request $request): JsonResponse
    {
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return response()->json(['recorded' => false], 202);
        }

        $userAgent = (string) $request->userAgent();
        if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headlesschrome/i', $userAgent)) {
            return response()->json(['recorded' => false], 202);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:500'],
        ]);

        $path = $this->normalizePath($data['path']);
        if (! $this->isTrackable($path)) {
            return response()->json(['recorded' => false], 202);
        }

        $today = now()->toDateString();
        $visitorHash = hash_hmac('sha256', implode('|', [
            (string) $request->ip(),
            $userAgent,
            $today,
        ]), (string) config('app.key'));

        PageView::create([
            'path' => $path,
            'referrer_host' => $this->referrerHost($data['referrer'] ?? null, $request),
            'visitor_hash' => $visitorHash,
            'viewed_on' => $today,
        ]);

        return response()->json(['recorded' => true], 202);
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $today = now()->startOfDay();
        $start = $today->copy()->subDays($days - 1)->toDateString();
        $end = $today->toDateString();
        $previousStart = $today->copy()->subDays($days * 2 - 1)->toDateString();
        $previousEnd = $today->copy()->subDays($days)->toDateString();

        $current = PageView::query()
            ->whereDate('viewed_on', '>=', $start)
            ->whereDate('viewed_on', '<=', $end);
        $previous = PageView::query()
            ->whereDate('viewed_on', '>=', $previousStart)
            ->whereDate('viewed_on', '<=', $previousEnd);

        $totals = (clone $current)
            ->selectRaw('COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors')
            ->first();
        $previousTotals = $previous
            ->selectRaw('COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors')
            ->first();
        $todayTotals = PageView::query()
            ->whereDate('viewed_on', $end)
            ->selectRaw('COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors')
            ->first();

        $dailyRows = (clone $current)
            ->selectRaw('DATE(viewed_on) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors')
            ->groupByRaw('DATE(viewed_on)')
            ->orderByRaw('DATE(viewed_on)')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $row = $dailyRows->get($date);
            $daily[] = [
                'date' => $date,
                'views' => (int) ($row->views ?? 0),
                'visitors' => (int) ($row->visitors ?? 0),
            ];
        }

        $topPages = (clone $current)
            ->selectRaw('path, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'path' => $row->path,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ]);

        $referrers = (clone $current)
            ->whereNotNull('referrer_host')
            ->selectRaw('referrer_host AS host, COUNT(*) AS views')
            ->groupBy('referrer_host')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'host' => $row->host,
                'views' => (int) $row->views,
            ]);

        return response()->json([
            'data' => [
                'range' => ['from' => $start, 'to' => $end, 'days' => $days],
                'totals' => [
                    'views' => (int) ($totals->views ?? 0),
                    'visitors' => (int) ($totals->visitors ?? 0),
                    'today_views' => (int) ($todayTotals->views ?? 0),
                    'today_visitors' => (int) ($todayTotals->visitors ?? 0),
                    'previous_views' => (int) ($previousTotals->views ?? 0),
                    'previous_visitors' => (int) ($previousTotals->visitors ?? 0),
                ],
                'daily' => $daily,
                'top_pages' => $topPages,
                'referrers' => $referrers,
            ],
        ]);
    }

    private function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return substr($path, 0, 255);
    }

    private function isTrackable(string $path): bool
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        // Guest self-service screens are private; the public hotel page itself
        // (e.g. /guest/demo-hotel) remains part of the audience.
        return ! preg_match('#^/guest/[^/]+/(?:login|my-reservations|thank-you)(?:/|$)#', $path);
    }

    private function referrerHost(?string $referrer, Request $request): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        if ($host === strtolower($request->getHost())) {
            return null;
        }

        return substr($host, 0, 120);
    }
}
