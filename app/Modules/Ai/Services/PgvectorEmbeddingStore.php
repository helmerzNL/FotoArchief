<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PgvectorEmbeddingStore
{
    public function available(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! Schema::hasColumn('ai_embeddings', 'embedding_vector')) {
            return false;
        }

        return DB::table('pg_extension')->where('extname', 'vector')->exists();
    }

    public function requireAvailable(): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages([
                'ai' => __('ai.errors.pgvector_unavailable'),
            ]);
        }

    }

    public function provision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! DB::table('pg_extension')->where('extname', 'vector')->exists()) {
            throw ValidationException::withMessages(['ai' => __('ai.errors.pgvector_unavailable')]);
        }
        DB::statement('ALTER TABLE ai_embeddings ADD COLUMN IF NOT EXISTS embedding_vector vector');
        DB::statement('CREATE INDEX IF NOT EXISTS ai_embeddings_generation_current_idx ON ai_embeddings (ai_embedding_generation_id) WHERE stale_at IS NULL');
    }

    /** @param list<string> $replacedAssetIds */
    public function copyCurrent(AiEmbeddingGeneration $base, AiEmbeddingGeneration $target, array $replacedAssetIds): void
    {
        $this->requireAvailable();
        $this->currentCandidates($base)
            ->whereNotIn('ai_embeddings.asset_id', $replacedAssetIds)
            ->whereNotNull('ai_embeddings.embedding_vector')
            ->select('ai_embeddings.*')
            ->chunkById(250, function ($rows) use ($target): void {
                $copies = [];
                foreach ($rows as $row) {
                    $copy = (array) $row;
                    $copy['id'] = (string) Str::ulid();
                    $copy['ai_embedding_generation_id'] = $target->id;
                    $copies[] = $copy;
                }
                DB::table('ai_embeddings')->insert($copies);
            }, 'ai_embeddings.id', 'id');
    }

    /**
     * @param  array<int, float|int>  $embeddingValues
     */
    public function persist(AiEmbedding $embedding, array $embeddingValues): void
    {
        $this->requireAvailable();
        $generation = $embedding->generation()->firstOrFail();
        $this->validateSpace($generation, $embeddingValues);

        $updated = DB::update(
            'UPDATE ai_embeddings SET embedding_vector = ?::vector, embedding = NULL WHERE id = ?',
            [$this->literal(array_values($embeddingValues)), $embedding->id],
        );
        if ($updated !== 1) {
            throw ValidationException::withMessages(['ai' => __('ai.errors.invalid_embedding')]);
        }
    }

    /**
     * @param  array<int, float|int>  $queryEmbedding
     * @return list<array{asset_id: string, accession_number: string|null, title: string|null, score: float, model_space: string}>
     */
    public function nearest(AiEmbeddingGeneration $generation, array $queryEmbedding, int $limit, ?Builder $eligibleAssets = null): array
    {
        $this->requireAvailable();
        $this->validateSpace($generation, $queryEmbedding);

        $literal = $this->literal(array_values($queryEmbedding));
        $rows = $this->currentCandidates($generation, $eligibleAssets)
            ->whereNotNull('ai_embeddings.embedding_vector')
            ->select([
                'ai_embeddings.asset_id',
                'assets.accession_number',
                'assets.title',
                'ai_embedding_generations.model_space',
            ])
            ->selectRaw('1 - (ai_embeddings.embedding_vector <=> ?::vector) as score', [$literal])
            ->orderByRaw('ai_embeddings.embedding_vector <=> ?::vector', [$literal])
            ->orderBy('ai_embeddings.asset_id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        return array_values($rows->map(static fn (object $row): array => [
            'asset_id' => (string) $row->asset_id,
            'accession_number' => $row->accession_number === null ? null : (string) $row->accession_number,
            'title' => $row->title === null ? null : (string) $row->title,
            'score' => round((float) $row->score, 6),
            'model_space' => (string) $row->model_space,
        ])->all());
    }

    public function currentCandidates(AiEmbeddingGeneration $generation, ?Builder $eligibleAssets = null, bool $activeOnly = true): Builder
    {
        $query = Asset::query()->toBase()
            ->join('ai_embeddings', 'ai_embeddings.asset_id', '=', 'assets.id')
            ->join('ai_embedding_generations', 'ai_embedding_generations.id', '=', 'ai_embeddings.ai_embedding_generation_id')
            ->join('asset_files', 'asset_files.id', '=', 'ai_embeddings.asset_file_id')
            ->where('ai_embeddings.ai_embedding_generation_id', $generation->id)
            ->when($activeOnly, fn (Builder $query): Builder => $query->where('ai_embedding_generations.status', AiEmbeddingGeneration::STATUS_ACTIVE))
            ->where('ai_embedding_generations.vector_backend', 'pgvector')
            ->whereNull('ai_embeddings.stale_at')
            ->whereColumn('asset_files.asset_id', 'assets.id')
            ->whereColumn('asset_files.sha256', 'ai_embeddings.source_file_sha256')
            ->whereColumn('assets.lock_version', 'ai_embeddings.source_asset_lock_version')
            ->where('asset_files.is_primary', true)
            ->where('asset_files.scanner_status', 'clean')
            ->where('asset_files.ingest_status', 'ready_private');

        if ($eligibleAssets !== null) {
            // OFFSET 0 keeps PostgreSQL from flattening eligibility into the
            // checksum joins and repeating public checks for every vector.
            // Wrap, rather than mutate, the caller's limits and offsets.
            $eligibleIds = $query->newQuery()->fromSub($eligibleAssets, 'eligible_assets');
            if ($query->getGrammar() instanceof PostgresGrammar) {
                $eligibleIds->offset(0);
            }
            $query->whereIn('assets.id', $eligibleIds);
        }

        return $query;
    }

    /** @param array<int, float|int> $values */
    private function validateSpace(AiEmbeddingGeneration $generation, array $values): void
    {
        if ($generation->vector_backend !== 'pgvector'
            || $generation->distance_metric !== 'cosine'
            || count($values) !== $generation->dimensions) {
            throw ValidationException::withMessages(['ai' => __('ai.errors.invalid_embedding')]);
        }
    }

    /**
     * @param  list<float|int>  $values
     */
    private function literal(array $values): string
    {
        if ($values === [] || array_any($values, static fn (mixed $value): bool => (! is_int($value) && ! is_float($value)) || ! is_finite((float) $value))
            || ! array_any($values, static fn (float|int $value): bool => abs($value) > 0.0)) {
            throw ValidationException::withMessages(['ai' => __('ai.errors.invalid_embedding')]);
        }

        return json_encode(array_values($values), JSON_THROW_ON_ERROR);
    }
}
