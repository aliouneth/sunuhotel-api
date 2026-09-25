<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HotelBillingPortalTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $role = 'manager'): array
    {
        Role::seedPolicies();
        $hotel = Hotel::factory()->create();
        $user = User::factory()->create(['hotel_id' => $hotel->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', $role)->first()]);
        HotelContext::clear();
        return [$hotel, $user];
    }

    public function test_tenant_can_read_bills_but_not_self_credit_wallet(): void
    {
        [$hotel, $manager] = $this->tenant('manager');
        $admin = User::factory()->platformAdmin()->create();
        HotelContext::clear();

        // Platform credits the wallet (payment collection lives platform-side).
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/subscriptions/{$hotel->id}/wallet-credit", ['amount_cents' => 500000])
            ->assertOk()
            ->assertJsonPath('data.wallet_balance_cents', 500000);

        // Hotel member sees their hotel's bills (billing_month, amounts, status).
        $res = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/billing')->assertOk();
        $this->assertArrayNotHasKey('wallet_balance_cents', $res->json('data'));
        $this->assertIsArray($res->json('data.invoices'));
        $this->assertNull($res->json('data.subscription'));

        // Hotel self top-up no longer exists (moved to platform).
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/billing/topup', ['amount_cents' => 100000])
            ->assertNotFound();

        // Unprivileged role (front-desk has only payments.view) can read bills.
        [$hotel2, $frontDesk] = $this->tenant('front-desk');
        $this->actingAs($frontDesk, 'sanctum')->getJson('/api/v1/billing')->assertOk();
    }
}