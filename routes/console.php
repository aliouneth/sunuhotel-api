<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('analytics:prune', function () {
    $deleted = App\Models\PageView::query()
        ->whereDate('viewed_on', '<', now()->subMonths(13)->toDateString())
        ->delete();

    $this->info("Deleted {$deleted} page views older than 13 months.");
})->purpose('Prune anonymous audience data older than 13 months');

Schedule::command('analytics:prune')->daily();

Artisan::command('billing:probe', function () {
    $billing = new App\Services\BillingService();
    $hotel = App\Models\Hotel::where('slug', App\Models\Hotel::SAMPLE_HOTEL_SLUG)->first();

    if (! $hotel) {
        $this->error('Sample hotel not found.');
        return 3;
    }

    $before = (int) $hotel->wallet_balance_cents;
    $this->info('hotel='.$hotel->slug.' wallet-before='.$before);

    $billing->creditWallet($hotel, 2500000);
    $this->info('wallet-after-credit='.$hotel->fresh()->wallet_balance_cents);

    $billing->ensureCurrentMonth();
    $inv = App\Models\MonthlyInvoice::where('hotel_id', $hotel->id)
        ->orderByDesc('billing_month')
        ->first();

    if ($inv) {
        $this->info('invoice='.$inv->billing_month.' status='.$inv->status
            .' paid='.$inv->paid_cents.'/'.$inv->amount_cents
            .' method='.$inv->payment_method);
    } else {
        $this->warn('No invoice generated.');
    }

    $this->info('wallet-final='.$hotel->fresh()->wallet_balance_cents);
    return 0;
})->purpose('Offline billing engine end-to-end probe');