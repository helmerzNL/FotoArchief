<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_embedding_generations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider_kind', 20);
            $table->string('provider_name', 120);
            $table->string('model_id', 160);
            $table->string('model_version', 160)->nullable();
            $table->string('model_space', 200)->unique();
            $table->unsignedInteger('dimensions');
            $table->string('distance_metric', 40);
            $table->string('vector_backend', 40);
            $table->string('status', 30)->default('building');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->json('capability_receipt')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('run_type', 40);
            $table->string('status', 30)->default('queued');
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained('asset_files')->cascadeOnDelete();
            $table->unsignedInteger('source_asset_lock_version');
            $table->string('source_file_sha256', 64);
            $table->string('provider_kind', 20);
            $table->string('provider_name', 120);
            $table->string('model_id', 160);
            $table->string('model_version', 160)->nullable();
            $table->string('model_space', 200)->nullable();
            $table->string('idempotency_key', 200)->unique();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('input_contract');
            $table->json('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['asset_file_id', 'source_file_sha256']);
        });

        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained('asset_files')->cascadeOnDelete();
            $table->unsignedInteger('source_asset_lock_version');
            $table->string('source_file_sha256', 64);
            $table->string('suggestion_type', 40);
            $table->string('language', 12)->default('nl');
            $table->text('value');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('review_status', 30)->default('pending');
            $table->foreignUlid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->json('evidence')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'review_status']);
            $table->index(['ai_run_id', 'suggestion_type']);
        });

        Schema::create('ai_embeddings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_embedding_generation_id')->constrained('ai_embedding_generations')->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained('asset_files')->cascadeOnDelete();
            $table->unsignedInteger('source_asset_lock_version');
            $table->string('source_file_sha256', 64);
            $table->json('embedding')->nullable();
            $table->string('external_vector_id', 200)->nullable();
            $table->timestampTz('indexed_at');
            $table->timestampTz('stale_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['ai_embedding_generation_id', 'asset_file_id', 'source_file_sha256'], 'ai_embeddings_generation_file_checksum_unique');
            $table->index(['asset_id', 'stale_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_embeddings');
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_embedding_generations');
    }
};
