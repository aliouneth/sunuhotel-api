<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelSubscription;
use App\Models\MonthlyInvoice;
use DB;
use RuntimeException;

class BillingService
{
    public const CURRENCY = 'XOF';

    public function ensureCurrentMonth(): int
    {
        $month = now()->format('Y-m');
        $created = 0;

        HotelSubscription::query()
            ->where('status', HotelSubscription::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
            ->with('plan')
            ->get()
            ->each(function (HotelSubscription $sub) use ($month, &$created) {
                $plan = $sub->plan;
                if (! $plan || ! $plan->is_active) {
                    return;
                }

                $invoice = MonthlyInvoice::query()
                    ->where('hotel_id', $sub->hotel_id)
                    ->where('billing_month', $month)
                    ->first();

                if (! $invoice) {
                    $invoice = MonthlyInvoice::create([
                        'hotel_id' => $sub->hotel_id,
                        'subscription_plan_id' => $plan->id,
                        'hotel_subscription_id' => $sub->id,
                        'billing_month' => $month,
                        'amount_cents' => $plan->monthly_rate_cents,
                        'paid_cents' => 0,
                        'currency' => $plan->currency ?: self::CURRENCY,
                        'status' => MonthlyInvoice::STATUS_PENDING,
                        'payment_method' => null,
                    ]);
                    $created++;
                }

                $this->settleFromWallet($invoice, $sub);
            });

        return $created;
    }

    public function settleFromWallet(MonthlyInvoice $invoice, HotelSubscription $sub): bool
    {
        if ($invoice->status === MonthlyInvoice::STATUS_PAID) {
            return true;
        }

        $due = $invoice->amount_cents - $invoice->paid_cents;
        if ($due <= 0) {
            return true;
        }

        return (bool) DB::transaction(function () use ($invoice, $sub, $due) {
            $fresh = MonthlyInvoice::lockForUpdate()->find($invoice->id);
            $hotel = Hotel::lockForUpdate()->find($sub->hotel_id);
            if (! $fresh || ! $hotel || $hotel->wallet_balance_cents <= 0) {
                return false;
            }

            $take = min($due, $hotel->wallet_balance_cents);
            $hotel->update(['wallet_balance_cents' => $hotel->wallet_balance_cents - $take]);

            $newPaid = $fresh->paid_cents + $take;
            $fresh->update([
                'paid_cents' => $newPaid,
                'payment_method' => 'wallet',
            ]);

            if ($newPaid >= $fresh->amount_cents) {
                $fresh->update([
                    'status' => MonthlyInvoice::STATUS_PAID,
                    'paid_at' => now(),
                ]);
            }

            return $fresh->fresh()->status === MonthlyInvoice::STATUS_PAID;
        });
    }

    public function markOverdue(): int
    {
        $lastMonth = now()->subMonth()->format('Y-m');

        return MonthlyInvoice::query()
            ->where('billing_month', $lastMonth)
            ->where('status', MonthlyInvoice::STATUS_PENDING)
            ->update(['status' => MonthlyInvoice::STATUS_OVERDUE]);
    }

    public function suspendOverdue(): int
    {
        $lastMonth = now()->subMonth()->format('Y-m');
        $suspended = 0;

        MonthlyInvoice::query()
            ->where('billing_month', $lastMonth)
            ->where('status', MonthlyInvoice::STATUS_OVERDUE)
            ->get()
            ->each(function (MonthlyInvoice $invoice) use (&$suspended) {
                $hotel = Hotel::find($invoice->hotel_id);
                if (! $hotel || $hotel->status === 'suspended') {
                    return;
                }

                DB::transaction(function () use ($hotel) {
                    $locked = Hotel::lockForUpdate()->find($hotel->id);
                    $locked->update(['status' => 'suspended']);

                    HotelSubscription::query()
                        ->where('hotel_id', $locked->id)
                        ->where('status', HotelSubscription::STATUS_ACTIVE)
                        ->update([
                            'status' => HotelSubscription::STATUS_DEACTIVATED,
                            'deactivation_reason' => 'unpaid monthly invoice',
                        ]);
                });

                $suspended++;
            });

        return $suspended;
    }

    public function creditWallet(Hotel $hotel, int $amountCents): int
    {
        if ($amountCents <= 0) {
            throw new RuntimeException('Wallet credit must be positive.');
        }

        return DB::transaction(function () use ($hotel, $amountCents) {
            $locked = Hotel::lockForUpdate()->find($hotel->id);
            $locked->update(['wallet_balance_cents' => $locked->wallet_balance_cents + $amountCents]);
            return $locked->fresh()->wallet_balance_cents;
        });
    }

    /**
     * Bill a hotel for an extra charge (e.g. a platform promotion fee) by
     * adding it to the current month's invoice. If that invoice was already
     * fully paid, it is reopened (pending) so the new balance is tracked and
     * can be settled from the wallet on the next billing pass.
     */
    public function billPromotion(Hotel $hotel, int $amountCents, string $title): void
    {
        if ($amountCents <= 0) {
            return;
        }

        $month = now()->format('Y-m');

        DB::transaction(function () use ($hotel, $amountCents, $title, $month) {
            $invoice = MonthlyInvoice::query()
                ->where('hotel_id', $hotel->id)
                ->where('billing_month', $month)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                $this->ensureCurrentMonth();
                $invoice = MonthlyInvoice::query()
                    ->where('hotel_id', $hotel->id)
                    ->where('billing_month', $month)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $invoice) {
                return;
            }

            if ($invoice->status === MonthlyInvoice::STATUS_PAID) {
                $invoice->update([
                    'status' => MonthlyInvoice::STATUS_PENDING,
                    'paid_at' => null,
                ]);
            }

            $invoice->update([
                'amount_cents' => $invoice->amount_cents + $amountCents,
                'notes' => trim(implode(' · ', array_filter([$invoice->notes, $title]))),
            ]);
        });
    }
}