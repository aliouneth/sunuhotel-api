<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for platform hotel Excel imports.
 *
 * `hotel_imports` records one row per import action (staged upload, completed
 * run, failed run) with the mapping and the outcome counters; `hotel_imports`
 * is intentionally verbose because the requirement is to be able to answer
 * "who imported what, when, from which file, with which mapping" months later.
 *
 * `hotel_import_issues` keeps row-level detail for duplicates (flagged but
 * still imported) and invalid rows (skipped), bounded by the importer so a
 * 20k-row file cannot bloat the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name', 191);
            $table->string('stored_path', 255)->nullable();
            $table->string('status', 20)->default('staged')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('mapped_fields')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('summary')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('hotel_import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('kind', 20);
            $table->string('reason', 191);
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['hotel_import_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_import_issues');
        Schema::dropIfExists('hotel_imports');
    }
};
