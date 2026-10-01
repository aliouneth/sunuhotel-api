<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Other Services" is a free-text catch-all (spa, airport shuttle, laundry,
 * restaurant, ...) kept as a single blob so a hotel can list services without
 * a catalogue table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->text('other_services')->nullable()->after('comment')
                ->comment('Free-text list of additional services offered by the hotel');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('other_services');
        });
    }
};
