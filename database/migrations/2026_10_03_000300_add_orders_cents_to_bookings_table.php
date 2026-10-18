<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the on-site orders subtotal to bookings. The booking total becomes
 * `subtotal + tax + orders - discount`; orders are untaxed so they enter the
 * total directly. Defaults to 0 so existing bookings are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('orders_cents')->default(0)->after('discount_cents')->comment('Active on-site orders subtotal (untaxed)');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('orders_cents');
        });
    }
};
