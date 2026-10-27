<?php

namespace App\Services;

use App\Models\BookingIntent;
use App\Models\Hotel;
use App\Models\MonthlyInvoice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the PayDunya "Paiement avec redirection" (redirect checkout)
 * HTTP/JSON API — the recommended flow: the merchant creates a checkout invoice
 * and redirects the customer to PayDunya's hosted page (mobile money wallets +
 * cards). Confirmation arrives two ways:
 *
 *   - return_url  : browser redirect after payment, PayDunya appends ?token=...
 *   - callback_url: server-to-server IPN (POST, x-www-form-urlencoded)
 *
 * Both are only hints; the authoritative status is always re-read from the
 * `confirm` endpoint, whose payload is signed with a SHA-512 hash of the
 * account master key.
 *
 * Docs: https://developers.paydunya.com/doc/FR/http_json
 * Amounts: PayDunya expects major units (e.g. FCFA), while the app stores
 * amounts in cents, so amounts are divided by 100 on the way out.
 */
class PayDunyaService
{
    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    private const BASE_TEST = 'https://app.paydunya.com/sandbox-api/v1';

    private const BASE_LIVE = 'https://app.paydunya.com/api/v1';

    public function mode(): string
    {
        return strtolower((string) config('services.paydunya.mode', self::MODE_TEST)) === self::MODE_LIVE
            ? self::MODE_LIVE
            : self::MODE_TEST;
    }

    public function mock(): bool
    {
        return (bool) config('services.paydunya.mock', false);
    }

    public function baseUrl(): string
    {
        return $this->mode() === self::MODE_LIVE ? self::BASE_LIVE : self::BASE_TEST;
    }

    /**
     * Whether the gateway has enough credentials to talk to PayDunya. When it
     * does not (e.g. a self-hosted install that never signed up) the pay
     * endpoint degrades to a 503 instead of sending a half-formed request.
     */
    public function configured(): bool
    {
        if ($this->mock()) {
            return true;
        }

        return $this->credential('master_key') !== ''
            && $this->credential('private_key') !== ''
            && $this->credential('token') !== '';
    }

    /**
     * Create a redirect checkout invoice.
     *
     * @param  array{return_url: string, cancel_url: string, callback_url: string}  $urls
     * @return array{token: string, url: string}
     */
    public function createInvoice(MonthlyInvoice $invoice, Hotel $hotel, array $urls): array
    {
        $dueCents = max(0, $invoice->amount_cents - $invoice->paid_cents);

        return $this->createCheckout([
            'invoice' => [
                'total_amount' => $this->toMajorUnits($dueCents),
                'description' => sprintf(
                    'Abonnement SunuHotel %s - %s',
                    $invoice->billing_month,
                    $hotel->name,
                ),
                'customer' => array_filter([
                    'name' => $hotel->name,
                    'email' => $hotel->email,
                    'phone' => $hotel->phone,
                ]),
            ],
            'store' => [
                'name' => (string) config('services.paydunya.store_name', 'SunuHotel'),
            ],
            'custom_data' => [
                'invoice_id' => $invoice->id,
                'hotel_id' => $hotel->id,
                'billing_month' => $invoice->billing_month,
            ],
            'actions' => [
                'return_url' => $urls['return_url'],
                'cancel_url' => $urls['cancel_url'],
                'callback_url' => $urls['callback_url'],
            ],
        ]);
    }

    /**
     * Create a redirect checkout invoice for a guest's stay. The intent is
     * identified in custom_data so the IPN can fulfil it (create the actual
     * Booking) after payment; no Booking exists yet at this point.
     *
     * @param  array{return_url: string, cancel_url: string, callback_url: string}  $urls
     * @return array{token: string, url: string}
     */
    public function createBookingInvoice(BookingIntent $intent, Hotel $hotel, array $urls): array
    {
        return $this->createCheckout([
            'invoice' => [
                'total_amount' => $this->toMajorUnits($intent->total_cents),
                'description' => sprintf(
                    'Reservation %s - %s',
                    $hotel->name,
                    $intent->check_in?->toDateString(),
                ),
                'customer' => array_filter([
                    'name' => trim($intent->guest_first_name.' '.$intent->guest_last_name),
                    'email' => $intent->guest_email,
                    'phone' => $intent->guest_phone,
                ]),
            ],
            'store' => [
                'name' => (string) config('services.paydunya.store_name', 'SunuHotel'),
            ],
            'custom_data' => [
                'type' => 'booking',
                'intent_id' => $intent->id,
                'hotel_id' => $hotel->id,
            ],
            'actions' => [
                'return_url' => $urls['return_url'],
                'cancel_url' => $urls['cancel_url'],
                'callback_url' => $urls['callback_url'],
            ],
        ]);
    }

    /**
     * POST a checkout-invoice payload and normalise the response.
     *
     * @return array{token: string, url: string}
     */
    private function createCheckout(array $payload): array
    {
        if ($this->mock()) {
            $token = 'MOCK_'.bin2hex(random_bytes(8));
            $returnUrl = (string) ($payload['actions']['return_url'] ?? rtrim((string) config('app.frontend_url'), '/'));

            return [
                'token' => $token,
                'url' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'token='.urlencode($token),
            ];
        }

        $logoUrl = trim((string) config('services.paydunya.logo_url', ''));
        if ($logoUrl === '') {
            $logoUrl = rtrim((string) config('app.frontend_url'), '/').'/logo.png';
        }

        $payload['store'] = array_merge(
            ['logo_url' => $logoUrl],
            $payload['store'] ?? [],
        );
        if (empty($payload['store']['logo_url'])) {
            $payload['store']['logo_url'] = $logoUrl;
        }

        $response = Http::withHeaders($this->headers())
            ->acceptJson()
            ->post($this->baseUrl().'/checkout-invoice/create', $payload);

        $body = $response->json() ?? [];

        if (($body['response_code'] ?? null) !== '00' || empty($body['token'])) {
            throw new RuntimeException(
                'PayDunya refused to create the invoice: '
                .($body['response_text'] ?? 'HTTP '.$response->status()),
            );
        }

        return [
            'token' => (string) $body['token'],
            // `response_text` carries the hosted payment page URL on success.
            'url' => (string) ($body['response_text'] ?? ''),
        ];
    }

    /**
     * Re-read the authoritative state of a checkout invoice by its token.
     *
     * @return array<string, mixed>
     */
    public function confirm(string $token): array
    {
        if ($this->mock()) {
            $masterKey = $this->credential('master_key');
            $totalMajor = null;
            $customData = [];

            $intent = \App\Models\BookingIntent::query()->withoutGlobalScopes()->where('token', $token)->first();
            if ($intent) {
                $totalMajor = $this->toMajorUnits($intent->total_cents);
                $customData = ['intent_id' => $intent->id];
            } else {
                $invoice = \App\Models\MonthlyInvoice::query()->where('reference', $token)->first();
                if ($invoice) {
                    $dueCents = max(0, $invoice->amount_cents - $invoice->paid_cents);
                    $totalMajor = $this->toMajorUnits($dueCents);
                    $customData = ['invoice_id' => $invoice->id];
                }
            }

            return [
                'response_code' => '00',
                'response_text' => 'Transaction Found',
                'hash' => hash('sha512', $masterKey),
                'status' => 'completed',
                'invoice' => array_filter([
                    'token' => $token,
                    'total_amount' => $totalMajor,
                ], fn ($v) => $v !== null),
                'custom_data' => $customData,
            ];
        }

        $response = Http::withHeaders($this->headers())
            ->acceptJson()
            ->get($this->baseUrl().'/checkout-invoice/confirm/'.rawurlencode($token));

        return $response->json() ?? [];
    }

    /**
     * Verify the SHA-512 hash of the account master key that PayDunya sends in
     * both the IPN payload and the confirm response. This proves the callback
     * originated from PayDunya (and not from a third party hitting the public
     * IPN URL).
     */
    public function verifyHash(?string $hash): bool
    {
        $masterKey = $this->credential('master_key');

        if ($hash === null || $masterKey === '') {
            return false;
        }

        return hash_equals(hash('sha512', $masterKey), $hash);
    }

    public function toMajorUnits(int $cents): int
    {
        return (int) round($cents / 100);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'PAYDUNYA-MASTER-KEY' => $this->credential('master_key'),
            'PAYDUNYA-PUBLIC-KEY' => $this->credential('public_key'),
            'PAYDUNYA-PRIVATE-KEY' => $this->credential('private_key'),
            'PAYDUNYA-TOKEN' => $this->credential('token'),
        ];
    }

    private function credential(string $key): string
    {
        return trim((string) config("services.paydunya.{$key}", ''));
    }
}
