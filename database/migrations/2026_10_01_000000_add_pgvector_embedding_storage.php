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
        Schema::table('ai_embedding_generations', function (Blueprint $table): void {
            $table->dropUnique(['model_space']);
            $table->index(['provider_kind', 'model_space', 'status']);
            $table->foreignUlid('operation_run_id')->nullable()->unique()->constrained('operation_runs')->nullOnDelete();
            $table->foreignUlid('base_generation_id')->nullable()->constrained('ai_embedding_generations')->nullOnDelete();
            $table->string('requested_model', 160)->default('');
        });
        Schema::create('ai_embedding_heads', function (Blueprint $table): void {
            $table->string('provider_kind', 20)->primary();
            $table->foreignUlid('generation_id')->nullable()->constrained('ai_embedding_generations')->nullOnDelete();
        });
        foreach (DB::table('ai_embedding_generations')->where('status', 'active')->where('vector_backend', 'pgvector')
            ->orderByDesc('activated_at')->orderByDesc('id')->get(['id', 'provider_kind']) as $generation) {
            DB::table('ai_embedding_heads')->insertOrIgnore(['provider_kind' => $generation->provider_kind, 'generation_id' => $generation->id]);
        }

        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! DB::table('pg_extension')->where('extname', 'vector')->exists()) {
            return;
        }

        DB::statement('ALTER TABLE ai_embeddings ADD COLUMN IF NOT EXISTS embedding_vector vector');
        DB::statement('CREATE INDEX IF NOT EXISTS ai_embeddings_generation_current_idx ON ai_embeddings (ai_embedding_generation_id) WHERE stale_at IS NULL');
    }

    public function down(): void
    {
        // Forward-only: vector rows are derived data, but rollbacks must not erase an index generation.
    }
};
