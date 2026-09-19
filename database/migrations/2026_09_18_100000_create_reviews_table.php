<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            // A booking can be reviewed at most once (NULLs are allowed multiple times).
            $table->unsignedBigInteger('booking_id')->nullable()->unique();
            $table->string('guest_name', 120);
            $table->unsignedTinyInteger('rating'); // 1..5 stars
            $table->string('title', 120)->nullable();
            $table->text('comment');
            $table->string('status', 20)->default('published'); // published|hidden
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};