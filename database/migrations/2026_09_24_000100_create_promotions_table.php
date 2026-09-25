<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('original_rate_cents');
            $table->unsignedBigInteger('promo_rate_cents');
            $table->string('currency', 3)->default('XOF');
            $table->unsignedBigInteger('fee_cents')->default(0)
                ->comment('Amount billed to the hotel for this promotion.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_on', 'ends_on']);
            $table->index(['hotel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};