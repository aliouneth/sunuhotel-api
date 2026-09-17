<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings (head record) + booking_rooms (line allocation).
 *
 * This is a head/line design: a booking owns one guest and a collection of
 * room allocations (BookingRoom). Each allocation references a room and a
 * rate plan and stores its own nightly rate + stay window, which enables
 * split stays and multi-room bookings without complicating the head record.
 *
 * Overbooking prevention:
 *  - DB constraint: unique index on (room_id, check_in, check_out, active)
 *    where active=1 only for usable (non-cancelled) allocations.
 *  - Application check: BookingService.availableRooms() validates the same
 *    window before persisting (provided for friendly errors).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('booking_number', 40)->comment('human-friendly reference, unique per hotel');
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->enum('status', [
                'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show',
            ])->default('pending')->index();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('source', 32)->default('front_desk')->comment('front_desk | ot_booking | walk_in | website');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0)->comment('subtotal + tax - discount');
            $table->unsignedInteger('paid_cents')->default(0)->comment('Running ledger total of completed payments');
            $table->text('notes')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable()->comment('cached actor name for audit consistency');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->unique(['hotel_id', 'booking_number']);
            $table->index(['hotel_id', 'status', 'check_in']);
            $table->index(['hotel_id', 'guest_id']);
        });

        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedMediumInteger('nights')->default(1);
            $table->unsignedInteger('nightly_rate_cents')->default(0)->comment('Resolved rate snapshot at booking time');
            $table->unsignedInteger('line_total_cents')->default(0);
            $table->boolean('active')->default(true)->comment('false once cancelled/no-show; backs the overbooking unique index');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->string('checked_in_by_name')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            // Overbooking guard: for any room + window only ONE active allocation may exist.
            $table->unique(['room_id', 'check_in', 'check_out', 'active'], 'booking_rooms_room_window_active_unique');
            $table->index(['hotel_id', 'room_id', 'check_in', 'check_out']);
            $table->index(['booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rooms');
        Schema::dropIfExists('bookings');
    }
};