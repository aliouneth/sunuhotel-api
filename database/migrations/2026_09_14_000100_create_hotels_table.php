<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `hotels` is the TENANT model of the platform.
 *
 * Every tenant-owned row across the database carries a `hotel_id`.
 * Enforcement happens at three levels:
 *   1. NOT NULL hotel_id on tenant tables (consistency)
 *   2. Eloquent global scope (HotelScope) applied via the BelongsToHotel trait
 *   3. Laravel route/controller middleware ensuring a context
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique()->index();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('currency', 3)->default('USD');
            $table->decimal('tax_rate', 5, 2)->default(0)->comment('Default VAT/sales tax percentage applied to room revenue');
            $table->time('check_in_time')->default('15:00');
            $table->time('check_out_time')->default('11:00');
            $table->string('logo_path')->nullable();
            $table->json('settings')->nullable()->comment('Flexible per-tenant key/value settings (theme, notifications, etc.)');
            $table->enum('status', ['active', 'suspended', 'trial'])->default('active')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Global lookup table for slug -> hotel resolution (case-insensitive slugs).
        Schema::create('hotel_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_slugs');
        Schema::dropIfExists('hotels');
    }
};