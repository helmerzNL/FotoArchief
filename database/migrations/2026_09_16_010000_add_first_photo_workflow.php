<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignUlid('created_by_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->index(['created_by_user_id', 'created_at']);
        });
        Schema::table('asset_files', function (Blueprint $table): void {
            $table->string('storage_disk', 100)->nullable()->after('asset_id');
            $table->unsignedInteger('pixel_width')->nullable();
            $table->unsignedInteger('pixel_height')->nullable();
            $table->jsonb('technical_metadata')->nullable();
            $table->jsonb('derivatives')->nullable();
            $table->string('scanner_status', 30)->default('unscanned')->index();
            $table->text('failure_reason')->nullable();
        });
        Schema::create('quarantine_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_disk', 100);
            $table->string('storage_key', 1024)->unique();
            $table->string('original_filename', 1024)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->string('status', 30)->default('queued')->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->string('claim_token', 40)->nullable();
            $table->timestampsTz();
        });
        Schema::create('asset_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 60);
            $table->jsonb('details')->nullable();
            $table->timestampsTz();
            $table->index(['asset_id', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assets DROP CONSTRAINT assets_date_precision_check');
            DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_date_precision_check CHECK (date_precision IN ('exact', 'circa', 'year', 'range', 'before', 'after', 'decade', 'unknown'))");
        }
    }

    public function down(): void
    {
        // Archival records and revisions are forward-only.
    }
};
