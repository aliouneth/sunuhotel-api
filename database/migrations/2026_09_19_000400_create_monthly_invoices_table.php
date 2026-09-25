<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One invoice per hotel per billing month. Carries the plan snapshot (rate +
 * currency) captured at generation time so later plan changes never rewrite
 * historical billing. Status transitions: pending -> paid, pending -> overdue
 * (once the grace window passes), and cancelled for zeroed/inactive hotels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained();
            $table->foreignId('subscription_plan_id')->constrained();
            $table->foreignId('hotel_subscription_id')->nullable()->constrained();
            $table->string('billing_month', 7)
                ->comment('YYYY-MM of the billed period');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->string('currency', 3)->default('XOF');
            $table->string('status', 20)->default('pending')
                ->comment('pending, paid, overdue, cancelled');
            $table->string('payment_method', 30)->nullable()
                ->comment('cash, card, bank_transfer, mobile_money, check');
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'billing_month'], 'monthly_invoices_hotel_month_unique');
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_invoices');
    }
};
