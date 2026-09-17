<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Hotel staff directory. Salaries are expressed in minor currency
         * units (cents) of the hotel currency.
         */
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name', 120);
            $table->string('position', 80)->nullable();
            $table->unsignedInteger('salary_cents')->default(0);
            $table->date('hire_date')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'name']);
        });

        /**
         * Expense categories. The built-in 'salary' type (key = 'salary') is
         * used by the payroll flow; hotels can create their own categories.
         */
        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name', 100);
            $table->string('key', 60)->nullable()->comment('stable identifier, e.g. salary');
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->unique(['hotel_id', 'key']);
            $table->index(['hotel_id', 'name']);
        });

        /**
         * Expense ledger. A salary payment is an expense row with an
         * employee_id and the 'salary' expense type, so payroll is always part
         * of the hotel's expenses.
         */
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->foreignId('expense_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->date('incurred_on');
            $table->date('paid_on')->nullable();
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('paid')->index();
            $table->string('created_by_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->index(['hotel_id', 'incurred_on']);
            $table->index(['hotel_id', 'expense_type_id']);
            $table->index(['hotel_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_types');
        Schema::dropIfExists('employees');
    }
};