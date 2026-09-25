<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment of a hotel to a subscription plan. A hotel can be on at most one
 * active subscription; rows are retained for history (indexed by hotel_id +
 * effective_from) so plan upgrades/downgrades are trackable over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained();
            $table->foreignId('subscription_plan_id')->constrained();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active')
                ->comment('active, deactivated, cancelled');
            $table->string('deactivation_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_subscriptions');
    }
};
