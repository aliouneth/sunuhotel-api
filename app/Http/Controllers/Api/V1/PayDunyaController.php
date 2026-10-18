<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MonthlyInvoice;
use App\Services\BillingService;
use App\Services\PayDunyaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Online payment of a hotel's monthly platform invoice via PayDunya.
 *
 * Flow:
 *   1. POST /billing/invoices/{invoice}/paydunya (tenant auth) creates a
 *      PayDunya checkout invoice and returns its hosted payment URL. The URL
 *      carries return_url / cancel_url pointing back at the billing screen and
 *      a callback_url pointing at the public IPN route below.
 *   2. The hotel pays on PayDunya's page (Orange Money, Wave, card, ...).
 *   3. PayDunya confirms out-of-band by POSTing to /paydunya/ipn. That route is
 *      public (no auth, no tenant): it is verified by hash + a server-side
 *      re-check of the transaction before the invoice is marked paid.
 */
class PayDunyaController extends Controller
{
    public function __construct(
        private readonly PayDunyaService $paydunya,
        private readonly BillingService $billing,
    ) {}

    /**
     * Create a checkout invoice and hand the browser its payment URL.
     */
    public function initiate(Request $request, MonthlyInvoice $invoice): JsonResponse
    {
        $this->authorize('payments.manage');

        $hotel = $request->user()->hotel;
        abort_unless($hotel && $invoice->hotel_id === $hotel->id, 404);

        if (! $this->paydunya->configured()) {
            return response()->json(['message' => 'Online payment is not configured.'], 503);
        }

        if ($invoice->status === MonthlyInvoice::STATUS_PAID) {
            return response()->json(['message' => 'This invoice is already paid.'], 422);
        }

        if ($invoice->amount_cents - $invoice->paid_cents <= 0) {
            return response()->json(['message' => 'Nothing is left to pay on this invoice.'], 422);
        }

        try {
            $result = $this->paydunya->createInvoice($invoice, $hotel, [
                'return_url' => $this->frontendUrl('/dashboard/billing'),
                'cancel_url' => $this->frontendUrl('/dashboard/billing'),
                'callback_url' => url('/api/v1/paydunya/ipn'),
            ]);
        } catch (\Throwable $e) {
            Log::error('PayDunya checkout creation failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not start the payment. Please try again.'], 502);
        }

        // Remember the token so the IPN can be reconciled even if it never
        // carries custom_data. Only the most recent attempt is stored.
        $invoice->update(['reference' => $result['token']]);

        return response()->json([
            'data' => [
                'payment_url' => $result['url'],
                'token' => $result['token'],
            ],
        ]);
    }

    /**
     * Public IPN endpoint: PayDunya POSTs the transaction result here
     * (application/x-www-form-urlencoded, nested under `data`).
     */
    public function ipn(Request $request): JsonResponse
    {
        $data = $request->input('data');

        if (! is_array($data)) {
            return response()->json(['message' => 'Malformed IPN payload.'], 400);
        }

        if (! $this->paydunya->verifyHash($data['hash'] ?? null)) {
            Log::warning('PayDunya IPN rejected: bad signature', ['token' => $data['invoice']['token'] ?? null]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $token = $data['invoice']['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Missing invoice token.'], 422);
        }

        // The IPN body is only a hint; re-read the authoritative status so a
        // forged or replayed body cannot mark an invoice paid.
        try {
            $confirm = $this->paydunya->confirm($token);
        } catch (\Throwable $e) {
            Log::error('PayDunya confirm failed', ['token' => $token, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not verify the payment.'], 502);
        }

        if (! $this->paydunya->verifyHash($confirm['hash'] ?? null)) {
            return response()->json(['message' => 'Invalid confirmation signature.'], 403);
        }

        if (($confirm['status'] ?? null) !== 'completed') {
            // pending / cancelled / failed: acknowledge but leave the invoice.
            return response()->json(['received' => true, 'status' => $confirm['status'] ?? 'unknown']);
        }

        $invoice = $this->resolveInvoice($confirm, $data, $token);
        if (! $invoice) {
            Log::warning('PayDunya IPN for an unknown invoice', ['token' => $token]);

            return response()->json(['message' => 'Unknown invoice.'], 404);
        }

        if ($invoice->status === MonthlyInvoice::STATUS_PAID) {
            return response()->json(['received' => true, 'status' => 'already_paid']);
        }

        $dueCents = max(0, $invoice->amount_cents - $invoice->paid_cents);
        $paidCents = $this->paydunya->toMajorUnits($dueCents) * 100;
        $confirmedMajor = $confirm['invoice']['total_amount'] ?? null;

        if ($confirmedMajor !== null && (int) $confirmedMajor < $this->paydunya->toMajorUnits($dueCents)) {
            Log::warning('PayDunya IPN amount mismatch', [
                'invoice_id' => $invoice->id,
                'expected' => $this->paydunya->toMajorUnits($dueCents),
                'received' => $confirmedMajor,
            ]);

            return response()->json(['message' => 'Amount mismatch.'], 409);
        }

        $this->billing->settleFromGateway($invoice, 'paydunya');

        return response()->json(['received' => true, 'status' => 'paid', 'paid_cents' => $paidCents]);
    }

    /**
     * Match an IPN/confirm payload back to its invoice: prefer the id we stashed
     * in custom_data (stable across repeated payment attempts), fall back to the
     * stored gateway token.
     */
    private function resolveInvoice(array $confirm, array $data, string $token): ?MonthlyInvoice
    {
        $invoiceId = $confirm['custom_data']['invoice_id']
            ?? $data['custom_data']['invoice_id']
            ?? null;

        if ($invoiceId) {
            $invoice = MonthlyInvoice::find($invoiceId);
            if ($invoice) {
                return $invoice;
            }
        }

        return MonthlyInvoice::where('reference', $token)->first();
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
