<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->after('capacity', function (Blueprint $table) {
                $table->unsignedInteger('daily_rate_cents')
                    ->nullable()
                    ->comment('Per-night flat rate on this room; null = fall back to the rate plan');
            });
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('daily_rate_cents');
        });
    }
};