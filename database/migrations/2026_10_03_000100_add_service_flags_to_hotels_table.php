<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional on-site services a hotel can offer, declared at registration and
 * editable later from the dashboard or the platform admin. They gate the
 * "orders" feature: staff can only add restaurant / spa / leisure orders to a
 * reservation when the matching flag is enabled for the hotel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->boolean('has_restaurant')->default(false)->after('other_services');
            $table->boolean('has_spa')->default(false)->after('has_restaurant');
            $table->boolean('has_leisure')->default(false)->after('has_spa');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['has_restaurant', 'has_spa', 'has_leisure']);
        });
    }
};
