<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * A rate plan is a pricing strategy attached to a room type.
         * Basis: `daily` (base_rate), `weekly` (7x multiplier), `seasonal` (date window multiplier).
         * Day-of-week and seasonal adjustments are stored as JSON rules evaluated by RateService.
         */
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('base_rate_cents')->default(0)->comment('Anchor nightly rate in minor currency units');
            $table->enum('basis', ['daily', 'weekly', 'seasonal'])->default('daily');
            $table->decimal('week_multiplier', 5, 2)->unsigned()->default(1.00)->comment('Applied for 7+ night stays when basis=weekly');
            $table->json('days_rules')->nullable()->comment('Per weekday multiplier map, e.g. {"sat":1.3,"sun":1.15}');
            $table->json('season_rules')->nullable()->comment('Seasonal windows: [{"start":"12-20","end":"01-05","multiplier":1.5}]');
            $table->boolean('tax_included')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'room_type_id', 'is_active']);
        });

        /**
         * Explicit price overrides for a given date (dynamic pricing / special events).
         * Highest precedence when resolving a nightly rate.
         */
        Schema::create('rate_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('rate_cents')->default(0);
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->unique(['rate_plan_id', 'date']);
            $table->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_overrides');
        Schema::dropIfExists('rate_plans');
    }
};