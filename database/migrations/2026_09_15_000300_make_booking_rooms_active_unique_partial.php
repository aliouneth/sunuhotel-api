<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The previous unique index (room_id, check_in, check_out, active) also made
 * inactive/active=0 tombstones unique, so editing a booking back to its
 * original window created two inactive rows with the same key and any later
 * deactivation collided. Replace it with a nullable column that the
 * application only fills while the allocation is active: inactive rows are
 * NULL and do not participate in the uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            if (Schema::hasIndex('booking_rooms', 'booking_rooms_room_window_active_unique')) {
		$table->dropForeign(['room_id']); 
                $table->dropUnique('booking_rooms_room_window_active_unique');
            }
        });

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->string('active_key', 80)->nullable()->after('active');
            $table->unique('active_key', 'booking_rooms_active_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropUnique('booking_rooms_active_key_unique');
            $table->dropColumn('active_key');
        });

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->unique(['room_id', 'check_in', 'check_out', 'active'], 'booking_rooms_room_window_active_unique');
        });
    }
};