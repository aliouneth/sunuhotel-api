<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest password reset. Guests who booked through a hotel's public page can
 * leave the password field empty ("set a password on a later visit"), which
 * stores a NULL password. Forcing a login against NULL password makes those
 * guests permanently locked out, so we add a self-service reset token pair
 * (token + expiry) used by the guest "forgot password" flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('password_reset_token')->nullable()->after('password');
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['password_reset_token', 'password_reset_expires_at']);
        });
    }
};
