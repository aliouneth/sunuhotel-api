<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant ownership (hotel_id) to users plus RBAC bookkeeping columns.
 *
 * A user is attached to exactly one hotel for the MVP (strictest isolation).
 * The platform owner (SaaS admin) can hold hotel_id = NULL and the `platform-admin` role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('hotel_id')->nullable()->index()->after('id');
            $table->boolean('is_active')->default(true)->index();
            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
            $table->dropColumn(['hotel_id', 'is_active']);
        });
    }
};