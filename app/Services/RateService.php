<?php

namespace App\Services;

use App\Models\RateOverride;
use App\Models\RatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves the nightly rate for a rate plan on a given date.
 *
 * Precedence (highest wins):
 *   1. explicit RateOverride for the date
 *   2. seasonal window multiplier (season_rules)  — wins over weekly/weekday surge
 *   3. per-weekday multiplier (days_rules)
 *   4. weekly discount (basis=weekly, stays >= 7 nights)
 *   5. base_rate_cents
 *
 * All math is done in integer cents to avoid float drift.
 */
final class RateService
{
    public function nightlyRateCents(
        RatePlan $plan,
        string|CarbonImmutable $date,
        int $stayNights = 1,
    ): int {
        $date = CarbonImmutable::parse($date);

        // 1) Explicit override for the date.
        $override = RateOverride::query()
            ->where('rate_plan_id', $plan->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($override) {
            return $override->rate_cents;
        }

        $base = $plan->base_rate_cents;

        // 2) Seasonal window multiplier (month-day pairs) — outranks weekday/weekly surge.
        $todayMd = $date->format('m-d');
        foreach ($plan->season_rules ?? [] as $season) {
            $start = $season['start'] ?? null;
            $end = $season['end'] ?? null;

            if ($start && $end && $this->inWindow($todayMd, $start, $end)) {
                return max(1, (int) round($base * (float) ($season['multiplier'] ?? 1.0)));
            }
        }

        // 3) Weekday multiplier.
        $rules = $plan->days_rules ?? [];
        $weekdayKey = strtolower($date->format('D'));
        if (! empty($rules[$weekdayKey])) {
            return max(1, (int) round($base * (float) $rules[$weekdayKey]));
        }

        // 4) Weekly multiplier for long stays.
        if ($plan->basis === 'weekly' && $stayNights >= 7 && $plan->week_multiplier > 0) {
            return max(1, (int) round($base * $plan->week_multiplier));
        }

        // 5) Base rate.
        return max(1, $base);
    }

    /**
     * Nightly rates for each date in [checkIn, checkOut).
     */
    public function ratesForStay(RatePlan $plan, string|CarbonImmutable $checkIn, string|CarbonImmutable $checkOut): Collection
    {
        $rates = collect();
        $cursor = CarbonImmutable::parse($checkIn);
        $end = CarbonImmutable::parse($checkOut);
        $nights = $cursor->diffInDays($end);

        while ($cursor->lt($end)) {
            $rates->push([
                'date' => $cursor->toDateString(),
                'rate_cents' => $this->nightlyRateCents($plan, $cursor, $nights),
            ]);
            $cursor = $cursor->addDay();
        }

        return $rates;
    }

    public function stayTotalCents(RatePlan $plan, string $checkIn, string $checkOut): int
    {
        return (int) $this->ratesForStay($plan, $checkIn, $checkOut)->sum('rate_cents');
    }

    /**
     * Window matches across year boundaries (e.g. 12-20 -> 01-05).
     */
    private function inWindow(string $md, string $start, string $end): bool
    {
        if ($start <= $end) {
            return $md >= $start && $md <= $end;
        }

        // Window crosses December/January boundary.
        return $md >= $start || $md <= $end;
    }
}