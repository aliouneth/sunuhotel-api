<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Basic payment ledger (no external gateway). A payment is always a
         * debit against a booking and is tracked in minor currency units.
         */
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents')->default(0);
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'mobile_money'])->default('cash');
            $table->enum('status', ['pending', 'completed', 'refunded', 'failed'])->default('pending')->index();
            $table->enum('type', ['payment', 'deposit', 'refund'])->default('payment');
            $table->string('reference')->nullable()->comment('external reference / receipt number');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'booking_id', 'status']);
        });

        /**
         * Tenant invitations for new staff members.
         */
        Schema::create('hotel_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('email');
            $table->string('role')->comment('Role granted on acceptance');
            $table->string('token', 64)->unique();
            $table->string('invited_by_name')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'email']);
        });

        /**
         * Append-only audit trail for critical mutations.
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->index(['hotel_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('hotel_invitations');
        Schema::dropIfExists('payments');
    }
};