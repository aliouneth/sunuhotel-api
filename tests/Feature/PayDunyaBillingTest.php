<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\MonthlyInvoice;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PayDunya online payment of a hotel's monthly platform invoice.
 *
 * All outbound HTTP is faked; the hash verification and the confirm re-check
 * are exercised for real against a fixed master key.
 */
class PayDunyaBillingTest extends TestCase
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

    private function invoiceFor(Hotel $hotel, int $amountCents = 10000): MonthlyInvoice
    {
        return MonthlyInvoice::factory()->create([
            'hotel_id' => $hotel->id,
            'amount_cents' => $amountCents,
            'paid_cents' => 0,
            'status' => MonthlyInvoice::STATUS_PENDING,
        ]);
    }

    private function fakeCheckout(string $token = 'test_TOKEN123', ?string $url = null): void
    {
        Http::fake([
            'app.paydunya.com/*/checkout-invoice/create' => Http::response([
                'response_code' => '00',
                'response_text' => $url ?? "https://app.paydunya.com/sandbox-checkout/invoice/{$token}",
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
                'invoice' => ['token' => 'test_TOKEN123', 'total_amount' => 100],
                'custom_data' => [],
            ], $overrides)),
        ]);
    }

    private function ipnPayload(string $token = 'test_TOKEN123', ?string $hash = null, int $invoiceId = 0): array
    {
        return [
            'data' => [
                'hash' => $hash ?? $this->hash(),
                'status' => 'completed',
                'invoice' => ['token' => $token],
                'custom_data' => ['invoice_id' => $invoiceId],
            ],
        ];
    }

    /* ------------------------------ Initiate ------------------------------ */

    public function test_manager_can_start_a_paydunya_checkout(): void
    {
        [$hotel, $manager] = $this->tenant('manager');
        $invoice = $this->invoiceFor($hotel, 10000);
        $this->fakeCheckout();

        $res = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/billing/invoices/{$invoice->id}/paydunya")
            ->assertOk()
            ->assertJsonPath('data.token', 'test_TOKEN123')
            ->assertJsonPath('data.payment_url', 'https://app.paydunya.com/sandbox-checkout/invoice/test_TOKEN123');

        $this->assertSame('test_TOKEN123', $invoice->fresh()->reference);
        $this->assertStringNotContainsString('test-private-key', (string) $res->getContent());

        Http::assertSent(function (Request $request) use ($invoice) {
            return str_contains($request->url(), '/checkout-invoice/create')
                && $request->hasHeader('PAYDUNYA-MASTER-KEY', self::MASTER_KEY)
                && $request['invoice']['total_amount'] === 100          // 10000 cents -> 100 XOF
                && $request['actions']['callback_url'] === url('/api/v1/paydunya/ipn')
                && $request['actions']['return_url'] === 'https://app.sunuhotel.test/dashboard/billing'
                && $request['custom_data']['invoice_id'] === $invoice->id;
        });
    }

    public function test_initiate_requires_the_manage_permission(): void
    {
        [$hotel, $frontDesk] = $this->tenant('front-desk');
        $invoice = $this->invoiceFor($hotel);

        $this->actingAs($frontDesk, 'sanctum')
            ->postJson("/api/v1/billing/invoices/{$invoice->id}/paydunya")
            ->assertForbidden();
    }

    public function test_initiate_returns_503_when_gateway_is_not_configured(): void
    {
        [$hotel, $manager] = $this->tenant('manager');
        $invoice = $this->invoiceFor($hotel);
        config()->set('services.paydunya.master_key', null);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/billing/invoices/{$invoice->id}/paydunya")
            ->assertStatus(503);
    }

    public function test_initiate_rejects_an_invoice_from_another_hotel(): void
    {
        [, $manager] = $this->tenant('manager');
        $otherHotel = Hotel::factory()->create();
        $foreign = $this->invoiceFor($otherHotel);
        HotelContext::clear();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/billing/invoices/{$foreign->id}/paydunya")
            ->assertNotFound();
    }

    public function test_initiate_rejects_an_already_paid_invoice(): void
    {
        [$hotel, $manager] = $this->tenant('manager');
        $invoice = $this->invoiceFor($hotel);
        $invoice->update(['status' => MonthlyInvoice::STATUS_PAID, 'paid_cents' => 10000]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/billing/invoices/{$invoice->id}/paydunya")
            ->assertStatus(422);
    }

    /* -------------------------------- IPN --------------------------------- */

    public function test_ipn_marks_the_invoice_paid(): void
    {
        $hotel = Hotel::factory()->create();
        $invoice = $this->invoiceFor($hotel, 10000);
        $this->fakeConfirm(['custom_data' => ['invoice_id' => $invoice->id]]);

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('test_TOKEN123', null, $invoice->id))
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $fresh = $invoice->fresh();
        $this->assertSame(MonthlyInvoice::STATUS_PAID, $fresh->status);
        $this->assertSame('paydunya', $fresh->payment_method);
        $this->assertSame(10000, $fresh->paid_cents);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_ipn_rejects_a_bad_hash(): void
    {
        $hotel = Hotel::factory()->create();
        $invoice = $this->invoiceFor($hotel);

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('test_TOKEN123', 'deadbeef', $invoice->id))
            ->assertForbidden();

        $this->assertSame(MonthlyInvoice::STATUS_PENDING, $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_ipn_ignores_a_non_completed_status(): void
    {
        $hotel = Hotel::factory()->create();
        $invoice = $this->invoiceFor($hotel);
        $this->fakeConfirm(['status' => 'pending', 'custom_data' => ['invoice_id' => $invoice->id]]);

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('test_TOKEN123', null, $invoice->id))
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertSame(MonthlyInvoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_ipn_rejects_an_underpayment(): void
    {
        $hotel = Hotel::factory()->create();
        $invoice = $this->invoiceFor($hotel, 10000);
        $this->fakeConfirm([
            'invoice' => ['token' => 'test_TOKEN123', 'total_amount' => 1],
            'custom_data' => ['invoice_id' => $invoice->id],
        ]);

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('test_TOKEN123', null, $invoice->id))
            ->assertStatus(409);

        $this->assertSame(MonthlyInvoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_ipn_is_idempotent_on_an_already_paid_invoice(): void
    {
        $hotel = Hotel::factory()->create();
        $invoice = $this->invoiceFor($hotel);
        $invoice->update(['status' => MonthlyInvoice::STATUS_PAID, 'paid_cents' => 10000]);
        $this->fakeConfirm(['custom_data' => ['invoice_id' => $invoice->id]]);

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('test_TOKEN123', null, $invoice->id))
            ->assertOk()
            ->assertJsonPath('status', 'already_paid');

        $this->assertSame(10000, $invoice->fresh()->paid_cents);
    }

    public function test_ipn_resolves_the_invoice_by_reference_when_custom_data_is_absent(): void
    {
        $hotel = Hotel::factory()->create();
        $invoice = $this->invoiceFor($hotel);
        $invoice->update(['reference' => 'test_TOKEN123']);
        $this->fakeConfirm();

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('test_TOKEN123'))
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertSame(MonthlyInvoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_ipn_for_an_unknown_token_is_rejected(): void
    {
        $this->fakeConfirm();

        $this->post('/api/v1/paydunya/ipn', $this->ipnPayload('nope_TOKEN'))
            ->assertNotFound();
    }

    public function test_ipn_with_a_malformed_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/paydunya/ipn', ['not_data' => true])->assertStatus(400);
    }
}
