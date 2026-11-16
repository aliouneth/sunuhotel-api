<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-party, privacy-friendly audience measurement.
 *
 * Only the public marketing/hotel pages are tracked (the collect endpoint
 * rejects admin routes). No IP address, user agent or cookie is stored: the
 * visitor id is an HMAC derived from the request metadata and the current day,
 * so it rotates daily and cannot be reversed into a person. A view is recorded
 * only after the visitor accepts the analytics consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255);
            $table->string('referrer_host', 120)->nullable();
            $table->char('visitor_hash', 64);
            $table->date('viewed_on');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['viewed_on', 'visitor_hash']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
