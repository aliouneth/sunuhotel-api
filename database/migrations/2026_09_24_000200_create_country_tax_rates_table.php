<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-managed default VAT/sales tax percentage per country
        // (ISO 3166-1 alpha-2). Used only as a reservation-tax fallback when
        // a hotel leaves its own tax_rate at 0.
        Schema::create('country_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->unique();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_tax_rates');
    }
};
