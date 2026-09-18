<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('base_capacity')->default(2);
            $table->unsignedTinyInteger('max_capacity')->default(4);
            $table->unsignedInteger('base_rate_cents')->default(0)->comment('Anchor nightly rate in minor currency units');
            $table->json('features')->nullable()->comment('Display attributes: view, bed type, etc.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'is_active']);
        });

        // Catalog of amenities a hotel can attach to rooms.
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name');
            $table->string('icon')->nullable()->comment('icon key resolved by the frontend material/heroicons set');
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index('hotel_id');
        });

        // Physical room inventory.
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('room_number');
            $table->unsignedSmallInteger('floor')->default(0);
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('capacity')->default(2);
            $table->enum('status', ['available', 'occupied', 'dirty', 'out_of_order', 'maintenance'])->default('available')->index();
            $table->string('keycard_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->unique(['hotel_id', 'room_number']);
            $table->index(['hotel_id', 'room_type_id', 'status']);
        });

        Schema::create('amenity_room', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('amenity_id')->constrained('amenities')->onDelete('cascade');
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->unique(['room_id', 'amenity_id']);
            $table->index('hotel_id');
        });


    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('amenity_room');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('room_types');
    }
};