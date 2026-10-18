<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-site orders (restaurant / spa / leisure) attached to a reservation.
 *
 * Orders are itemised (head order + order_items lines) and deliberately NOT
 * taxed: only the room charge is taxed. Their active total is rolled into the
 * booking's `orders_cents` so the final bill stays a single figure the
 * existing payment/balance flow already understands.
 *
 * Status: open | void. A void order is retained for the audit trail but
 * excluded from the booking total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->enum('category', ['restaurant', 'spa', 'leisure']);
            $table->enum('status', ['open', 'void'])->default('open');
            $table->unsignedInteger('total_cents')->default(0)->comment('Sum of line totals; frozen when the order is voided');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable()->comment('cached actor name for audit consistency');
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'booking_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('description', 191);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('line_total_cents')->default(0)->comment('quantity * unit_price_cents');
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
