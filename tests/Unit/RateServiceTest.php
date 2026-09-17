<?php

namespace Tests\Unit;

use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Services\RateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_override_has_highest_precedence(): void
    {
        $service = app(RateService::class);
        $plan = $this->plan();

        // Weekend rule would bump base 10000 -> 11500, but the override wins.
        RateOverride::create([
            'hotel_id' => $plan->hotel_id,
            'rate_plan_id' => $plan->id,
            'date' => '2026-10-10', // a Saturday
            'rate_cents' => 7777,
        ]);

        $this->assertSame(7777, $service->nightlyRateCents($plan, '2026-10-10'));
    }

    public function test_weekday_multiplier_applies(): void
    {
        $service = app(RateService::class);
        $plan = $this->plan();

        $this->assertSame(11500, $service->nightlyRateCents($plan, '2026-10-10')); // Sat -> 1.15
        $this->assertSame(10000, $service->nightlyRateCents($plan, '2026-10-12')); // Mon -> base
    }

    public function test_seasonal_window_multiplier_applies(): void
    {
        $service = app(RateService::class);
        $plan = $this->plan();
        $plan->update(['season_rules' => [['start' => '12-20', 'end' => '01-05', 'multiplier' => 1.5]]]);

        $this->assertSame(15000, $service->nightlyRateCents($plan, '2026-12-25'));
        $this->assertSame(15000, $service->nightlyRateCents($plan, '2027-01-02'));
        $this->assertSame(10000, $service->nightlyRateCents($plan, '2026-10-12'));
    }

    public function test_weekly_multiplier_applies_for_long_stays(): void
    {
        $service = app(RateService::class);
        $plan = $this->plan();
        $plan->update(['basis' => 'weekly', 'week_multiplier' => 0.9]);

        $this->assertSame(9000, $service->nightlyRateCents($plan, '2026-10-12', 7));
        $this->assertSame(10000, $service->nightlyRateCents($plan, '2026-10-12', 3));
    }

    public function test_stay_total_accumulates_nights(): void
    {
        $service = app(RateService::class);
        $plan = $this->plan();

        $this->assertSame(42500, $service->stayTotalCents($plan, '2026-10-09', '2026-10-13'));
    }

    private function plan(): RatePlan
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return RatePlan::create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'name' => 'BAR',
            'currency' => 'USD',
            'base_rate_cents' => 10000,
            'basis' => 'daily',
            'days_rules' => ['sat' => 1.15, 'sun' => 1.10],
            'season_rules' => [['start' => '12-20', 'end' => '01-05', 'multiplier' => 1.5]],
            'is_active' => true,
        ]);
    }
}