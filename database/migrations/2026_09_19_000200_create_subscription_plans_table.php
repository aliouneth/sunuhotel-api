<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level subscription plans. These are global (not hotel-scoped) and
 * are managed by the platform admin. Each hotel is assigned to exactly one
 * active plan at a time via the hotel_subscriptions table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('max_rooms')->default(0);
            $table->unsignedBigInteger('monthly_rate_cents')->default(0);
            $table->string('currency', 3)->default('XOF');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
