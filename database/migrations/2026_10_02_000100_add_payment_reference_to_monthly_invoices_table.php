<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External gateway reference for a monthly invoice payment. For PayDunya this
 * holds the checkout invoice token, which is how the IPN callback (and the
 * confirm API) are reconciled back to the invoice. The model already declared
 * this attribute as fillable; the column was never created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_invoices', function (Blueprint $table) {
            $table->string('reference', 120)->nullable()->after('payment_method')
                ->comment('external gateway reference (e.g. PayDunya invoice token)');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_invoices', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropColumn('reference');
        });
    }
};
