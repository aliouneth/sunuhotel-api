<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking intents: the "pay first, reserve after" staging record for online
 * (public) reservations.
 *
 * A guest who reserves on a hotel's public page must pay the full stay total
 * before any Booking exists. The intent captures the requested stay + guest
 * details + a price snapshot, and holds the PayDunya checkout token. Only when
 * the gateway confirms payment (IPN) is the real, `confirmed` Booking created
 * and linked back here (booking_id). Rooms are deliberately NOT held while the
 * intent is pending: no reservation exists until it is paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_intents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('token', 120)->nullable()->unique()->comment('PayDunya checkout token for the current attempt');
            $table->string('status', 20)->default('pending')->comment('pending | paid | failed');
            $table->string('guest_first_name', 100);
            $table->string('guest_last_name', 100);
            $table->string('guest_email', 191)->nullable();
            $table->string('guest_phone', 40)->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->json('rooms')->comment('Room ids requested for the whole stay');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0)->comment('Charged amount = subtotal + tax - discount');
            $table->string('currency', 3);
            $table->string('source', 32)->default('website');
            $table->text('notes')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_intents');
    }
};
