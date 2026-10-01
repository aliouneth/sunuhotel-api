<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('phone_2', 40)->nullable()->after('phone')
                ->comment('Secondary contact phone number');
            $table->text('description')->nullable()->after('website')
                ->comment('Public-facing hotel description');
            $table->text('comment')->nullable()->after('description')
                ->comment('Internal note, never shown publicly');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['phone_2', 'description', 'comment']);
        });
    }
};
