<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_records_anonymous_public_views_only(): void
    {
        $this->postJson('/api/v1/analytics/collect', [
            'path' => '/guest/demo-hotel?check_in=2026-01-01',
            'referrer' => 'https://www.search.example/results?q=hotel',
        ])->assertStatus(202)->assertJson(['recorded' => true]);

        $this->assertDatabaseHas('page_views', [
            'path' => '/guest/demo-hotel',
            'referrer_host' => 'search.example',
        ]);

        $this->postJson('/api/v1/analytics/collect', ['path' => '/dashboard/bookings'])
            ->assertStatus(202)
            ->assertJson(['recorded' => false]);

        $this->postJson('/api/v1/analytics/collect', ['path' => '/guest/demo-hotel/thank-you'])
            ->assertStatus(202)
            ->assertJson(['recorded' => false]);

        $this->postJson('/api/v1/analytics/collect', ['path' => '/privacy'], ['DNT' => '1'])
            ->assertStatus(202)
            ->assertJson(['recorded' => false]);

        $this->assertSame(1, PageView::query()->count());
    }

    public function test_platform_admin_can_read_aggregated_audience_summary(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $previousPeriod = now()->subDays(8)->toDateString();

        $rows = [
            ['path' => '/', 'visitor_hash' => str_repeat('a', 64), 'viewed_on' => $today, 'referrer_host' => 'example.com'],
            ['path' => '/', 'visitor_hash' => str_repeat('a', 64), 'viewed_on' => $today, 'referrer_host' => null],
            ['path' => '/guest/demo-hotel', 'visitor_hash' => str_repeat('b', 64), 'viewed_on' => $today, 'referrer_host' => null],
            ['path' => '/privacy', 'visitor_hash' => str_repeat('c', 64), 'viewed_on' => $yesterday, 'referrer_host' => null],
            ['path' => '/hotel-owners', 'visitor_hash' => str_repeat('d', 64), 'viewed_on' => $previousPeriod, 'referrer_host' => null],
        ];

        foreach ($rows as $row) {
            PageView::create($row);
        }

        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/analytics?days=7')
            ->assertOk()
            ->assertJsonPath('data.range.days', 7)
            ->assertJsonPath('data.totals.views', 4)
            ->assertJsonPath('data.totals.visitors', 3)
            ->assertJsonPath('data.totals.today_views', 3)
            ->assertJsonPath('data.totals.today_visitors', 2)
            ->assertJsonPath('data.totals.previous_views', 1)
            ->assertJsonPath('data.top_pages.0.path', '/')
            ->assertJsonPath('data.top_pages.0.views', 2)
            ->assertJsonPath('data.referrers.0.host', 'example.com')
            ->assertJsonCount(7, 'data.daily');
    }

    public function test_tenant_users_cannot_read_platform_audience_summary(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/analytics?days=30')
            ->assertForbidden();
    }
}
