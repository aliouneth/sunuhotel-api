<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the vendor/contractor "paid to" name and the payment method used to
 * settle an expense. Both are additive and nullable so existing rows and the
 * payroll salary expenses (which are paid to employees) keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('paid_to', 120)->nullable()->after('employee_id')
                ->comment('Vendor, contractor or supplier name; null = paid to the employee/staff');
            $table->string('payment_method', 30)->nullable()->after('paid_to')
                ->comment('cash, card, bank_transfer, mobile_money, check');
            $table->index(['hotel_id', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'payment_method']);
            $table->dropColumn(['paid_to', 'payment_method']);
        });
    }
};
