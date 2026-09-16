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
        Schema::create('data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('export_type', 30);
            $table->jsonb('asset_ids');
            $table->unsignedInteger('asset_count')->default(0);
            $table->string('status', 20)->default('queued')->index();
            $table->string('storage_disk', 100)->nullable();
            $table->string('storage_key', 1024)->nullable()->unique();
            $table->string('download_filename', 255)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->jsonb('manifest')->nullable();
            $table->char('download_token_hash', 64)->nullable();
            $table->timestampTz('download_token_expires_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->string('claim_token', 40)->nullable();
            $table->timestampsTz();
            $table->index(['created_by_user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_type_check CHECK (export_type IN ('metadata_json', 'metadata_csv', 'package_zip'))");
            DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check CHECK (status IN ('queued', 'running', 'ready', 'failed', 'expired', 'revoked'))");
        }
    }

    public function down(): void
    {
        // Export receipts stay auditable; the schema is forward-only.
    }
};
