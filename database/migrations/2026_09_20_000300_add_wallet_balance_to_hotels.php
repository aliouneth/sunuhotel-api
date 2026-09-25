<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            // Recurring-billing wallet. Hotels can prepay several months of
            // credit (a real provider like CinetPay is wired behind
            // PaymentGateway; when no gateway keys are set, the platform
            // admin simply records the paid amount which lands here).
            // BillingService consumes this balance each month to settle that
            // month's MonthlyInvoice before it ever becomes overdue.
            $table->unsignedBigInteger('wallet_balance_cents')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('wallet_balance_cents');
        });
    }
};
