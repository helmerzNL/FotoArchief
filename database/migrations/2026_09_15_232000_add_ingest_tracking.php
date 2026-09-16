<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_files', function (Blueprint $table): void {
            $table->string('ingest_status', 30)->default('quarantine')->index();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('publishable_at')->nullable();
        });

        Schema::create('processing_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_file_id')->constrained()->cascadeOnDelete();
            $table->string('job_type', 100);
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->jsonb('result')->nullable();
            $table->timestampsTz();
            $table->index(['asset_file_id', 'job_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');

        Schema::table('asset_files', function (Blueprint $table): void {
            $table->dropColumn([
                'ingest_status',
                'validated_at',
                'scanned_at',
                'processed_at',
                'publishable_at',
            ]);
        });
    }
};
