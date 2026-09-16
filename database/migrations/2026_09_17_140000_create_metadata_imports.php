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
        Schema::create('metadata_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_disk', 100);
            $table->string('storage_key', 1024)->unique();
            $table->string('original_filename', 1024)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->char('content_sha256', 64);
            $table->string('status', 30)->default('received')->index();
            $table->string('write_mode', 20)->default('fill_empty');
            $table->string('delimiter', 4)->default(',');
            $table->jsonb('column_mapping')->nullable();
            $table->jsonb('summary')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->string('claim_token', 40)->nullable();
            $table->timestampTz('analysed_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['created_by_user_id', 'created_at']);
        });

        Schema::create('metadata_import_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('metadata_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('accession_number', 255)->nullable();
            $table->foreignUlid('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('expected_lock_version')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->jsonb('mapped_values')->nullable();
            $table->jsonb('changes')->nullable();
            $table->jsonb('messages')->nullable();
            $table->timestampsTz();
            $table->unique(['metadata_import_id', 'row_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE metadata_imports ADD CONSTRAINT metadata_imports_status_check CHECK (status IN ('received', 'analysing', 'analysed', 'queued', 'running', 'completed', 'failed'))");
            DB::statement("ALTER TABLE metadata_imports ADD CONSTRAINT metadata_imports_write_mode_check CHECK (write_mode IN ('fill_empty', 'overwrite'))");
            DB::statement("ALTER TABLE metadata_import_rows ADD CONSTRAINT metadata_import_rows_status_check CHECK (status IN ('pending', 'ready', 'unchanged', 'error', 'applied', 'failed'))");
        }
    }

    public function down(): void
    {
        // Import receipts stay auditable; the schema is forward-only.
    }
};
