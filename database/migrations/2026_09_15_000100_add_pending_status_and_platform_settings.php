<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hotels get a review lifecycle: new registrations land on `pending`,
        // platform admins approve (active) or reject.
        Schema::table('hotels', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected', 'trial'])
                ->default('active')
                ->change();
        });

        // Single-row store for Sunuhotel corporate/office contact information
        // surfaced on the registration confirmation screen and the platform UI.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('hours')->nullable();
            $table->timestamps();
        });

        // Seed-friendly guaranteed single row.
        DB::table('platform_settings')->updateOrInsert(['id' => 1], ['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');

        Schema::table('hotels', function (Blueprint $table) {
            $table->enum('status', ['active', 'suspended', 'trial'])
                ->default('active')
                ->change();
        });
    }
};