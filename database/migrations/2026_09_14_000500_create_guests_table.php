<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('nationality', 3)->nullable();
            $table->enum('id_type', ['passport', 'national_id', 'driver_license', 'other'])->nullable();
            $table->string('id_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 3)->nullable();
            $table->json('preferences')->nullable()->comment('e.g. floor preference, room type, dietary needs');
            $table->text('notes')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'last_name', 'first_name']);
            $table->index(['hotel_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};