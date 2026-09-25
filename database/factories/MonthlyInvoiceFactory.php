<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\MonthlyInvoice;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyInvoice>
 */
class MonthlyInvoiceFactory extends Factory
{
    protected $model = MonthlyInvoice::class;

    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'hotel_subscription_id' => null,
            'billing_month' => now()->format('Y-m'),
            'amount_cents' => 10000,
            'paid_cents' => 0,
            'currency' => 'XOF',
            'status' => MonthlyInvoice::STATUS_PENDING,
            'payment_method' => null,
            'paid_at' => null,
            'notes' => null,
        ];
    }
}