<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\ArchiveOperations\Models\OperationRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiIndexGenerationService
{
    public function __construct(private readonly PgvectorEmbeddingStore $vectors) {}

    public function forRun(OperationRun $run): ?AiEmbeddingGeneration
    {
        return AiEmbeddingGeneration::query()->where('operation_run_id', $run->id)->first();
    }

    public function activeForProvider(string $provider): ?AiEmbeddingGeneration
    {
        return AiEmbeddingGeneration::query()->where('provider_kind', $provider)
            ->where('vector_backend', 'pgvector')->where('status', AiEmbeddingGeneration::STATUS_ACTIVE)
            ->whereIn('id', DB::table('ai_embedding_heads')->select('generation_id')->where('provider_kind', $provider))
            ->first();
    }

    public function create(OperationRun $run, string $provider, string $model, string $space, int $dimensions): AiEmbeddingGeneration
    {
        return DB::transaction(function () use ($run, $provider, $model, $space, $dimensions): AiEmbeddingGeneration {
            DB::table('ai_embedding_heads')->insertOrIgnore(['provider_kind' => $provider, 'generation_id' => null]);
            $head = DB::table('ai_embedding_heads')->where('provider_kind', $provider)->lockForUpdate()->first();
            $existing = $this->forRun($run);
            if ($existing !== null) {
                $this->assertCompatible($existing, $provider, $model, $space, $dimensions);

                return $existing;
            }
            $base = $head?->generation_id !== null ? AiEmbeddingGeneration::query()->whereKey($head->generation_id)->firstOrFail() : null;
            $generation = AiEmbeddingGeneration::query()->create([
                'operation_run_id' => $run->id,
                'base_generation_id' => $base?->id,
                'provider_kind' => $provider,
                'provider_name' => in_array($provider, ['gemini', 'openrouter'], true) ? "native-{$provider}" : ($provider === 'external' ? 'external-http' : 'owned-http'),
                'requested_model' => $model,
                'model_id' => $space,
                'model_space' => $space,
                'dimensions' => $dimensions,
                'distance_metric' => 'cosine',
                'vector_backend' => 'pgvector',
                'status' => AiEmbeddingGeneration::STATUS_BUILDING,
                'capability_receipt' => ['image_embeddings' => true, 'text_embeddings_required_for_queries' => true, 'same_embedding_space' => true],
            ]);
            if ($base !== null && $base->model_space === $space && $base->dimensions === $dimensions
                && $base->distance_metric === 'cosine' && $base->vector_backend === 'pgvector') {
                $this->vectors->copyCurrent($base, $generation, $run->payload['asset_ids'] ?? []);
            }

            return $generation;
        });
    }

    public function assertCompatible(AiEmbeddingGeneration $generation, string $provider, string $model, string $space, int $dimensions): void
    {
        if ($generation->provider_kind !== $provider || $generation->requested_model !== $model
            || $generation->model_space !== $space || $generation->dimensions !== $dimensions
            || $generation->distance_metric !== 'cosine' || $generation->vector_backend !== 'pgvector'
            || $generation->status !== AiEmbeddingGeneration::STATUS_BUILDING) {
            throw new RuntimeException(__('ai.errors.model_space_conflict', ['space' => $space]));
        }
    }

    public function activate(AiEmbeddingGeneration $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $head = DB::table('ai_embedding_heads')->where('provider_kind', $generation->provider_kind)->lockForUpdate()->first();
            $generation->refresh();
            if ($head?->generation_id === $generation->id && $generation->status === AiEmbeddingGeneration::STATUS_ACTIVE) {
                return;
            }
            if ($head === null || $head->generation_id !== $generation->base_generation_id
                || $generation->status !== AiEmbeddingGeneration::STATUS_BUILDING) {
                throw new RuntimeException(__('ai.errors.generation_outdated'));
            }
            $run = OperationRun::query()->whereKey($generation->operation_run_id)->firstOrFail();
            $assetIds = $run->payload['asset_ids'] ?? [];
            if ($assetIds === [] || $run->processed_items !== count($assetIds)
                || $this->vectors->currentCandidates($generation, activeOnly: false)->whereIn('ai_embeddings.asset_id', $assetIds)
                    ->distinct()->count('ai_embeddings.asset_id') !== count($assetIds)) {
                throw new RuntimeException(__('ai.errors.generation_sources_changed'));
            }
            AiEmbeddingGeneration::query()->where('provider_kind', $generation->provider_kind)
                ->where('status', AiEmbeddingGeneration::STATUS_ACTIVE)
                ->update(['status' => AiEmbeddingGeneration::STATUS_RETIRED, 'retired_at' => now()]);
            $generation->forceFill(['status' => AiEmbeddingGeneration::STATUS_ACTIVE, 'activated_at' => now(), 'retired_at' => null])->save();
            DB::table('ai_embedding_heads')->where('provider_kind', $generation->provider_kind)->update(['generation_id' => $generation->id]);
            app(AiOperationAuditService::class)->generation($run, $generation);
        });
    }
}
