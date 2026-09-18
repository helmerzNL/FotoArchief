<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AiIndexWorkbench
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly AiIndexGenerationService $generations,
        private readonly PgvectorEmbeddingStore $vectors,
    ) {}

    public function coverage(User $user, ?string $collection): Builder
    {
        $settings = $this->configuration->effective();
        $provider = (string) ($settings['embeddings_provider'] ?? '');
        $generation = $this->generations->activeForProvider($provider);
        $assets = Asset::query()->select(['assets.id', 'assets.title', 'assets.accession_number'])
            ->when(! $user->hasPermission('assets.publish'), fn ($q) => $q->where('created_by_user_id', $user->id))
            ->when($collection, fn ($q) => $q->whereHas('collections', fn ($q) => $q->where('collections.id', $collection)));
        $assets->selectSub(AssetFile::query()->selectRaw('COUNT(*)')->whereColumn('asset_id', 'assets.id')
            ->where('is_primary', true)->where('scanner_status', 'clean')->where('ingest_status', 'ready_private'), 'eligible_count');
        $assets->selectSub(AiEmbedding::query()->selectRaw('COUNT(*)')->whereColumn('asset_id', 'assets.id')
            ->whereHas('generation', fn ($q) => $q->where('provider_kind', $provider)), 'historical_count');
        $assets->selectSub(OperationRunAuditEvent::query()->select('event_type')->whereColumn('asset_id', 'assets.id')
            ->whereIn('event_type', ['ai.index.item_failed', 'ai.index.item_succeeded'])
            ->whereHas('operationRun', fn ($q) => $q->where('payload->provider', $provider))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1), 'last_event');
        if ($generation !== null && $generation->requested_model === (string) ($settings['embeddings_model'] ?? '') && $this->vectors->available()) {
            $current = $this->vectors->currentCandidates($generation)->select('ai_embeddings.asset_id')
                ->whereNotNull('ai_embeddings.embedding_vector');
        } else {
            $current = DB::table('ai_embeddings')->select('asset_id')->whereRaw('1 = 0');
        }
        $assets->selectSub(DB::query()->fromSub($current, 'current_vectors')->selectRaw('COUNT(*)')
            ->whereColumn('current_vectors.asset_id', 'assets.id'), 'current_count');
        $query = DB::query()->fromSub($assets->toBase(), 'candidate')
            ->select('candidate.*')
            ->selectRaw("CASE WHEN eligible_count = 0 THEN 'excluded' WHEN current_count > 0 THEN 'current' WHEN last_event = 'ai.index.item_failed' THEN 'failed' WHEN historical_count > 0 THEN 'stale' ELSE 'missing' END AS coverage_status");

        return $query;
    }

    public function configurationFingerprint(): string
    {
        $settings = $this->configuration->effective();
        $provider = (string) ($settings['embeddings_provider'] ?? '');

        return hash('sha256', json_encode([$provider, $settings['embeddings_model'] ?? '', $this->generations->activeForProvider($provider)?->model_space], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $ids */
    public function repair(User $user, string $collection, array $ids, ?string $expectedHead, string $expectedConfiguration): OperationRun
    {
        abort_unless($user->hasPermission('users.manage') || $user->hasPermission('catalogue.manage'), 403);

        return DB::transaction(function () use ($user, $collection, $ids, $expectedHead, $expectedConfiguration): OperationRun {
            $settings = $this->configuration->effective();
            $provider = (string) ($settings['embeddings_provider'] ?? '');
            $head = DB::table('ai_embedding_heads')->where('provider_kind', $provider)->lockForUpdate()->first();
            if (($head->generation_id ?? null) !== $expectedHead || ! hash_equals($this->configurationFingerprint(), $expectedConfiguration)) {
                throw ValidationException::withMessages(['selected' => __('indexwork.changed')]);
            }
            $eligible = DB::query()->fromSub($this->coverage($user, $collection), 'coverage')
                ->whereIn('id', $ids)->whereIn('coverage_status', ['missing', 'stale'])->pluck('id')->all();
            if (count($eligible) !== count($ids)) {
                throw ValidationException::withMessages(['selected' => __('indexwork.selection_changed')]);
            }
            $references = array_values(Asset::query()->whereIn('id', $ids)->get()->map(fn (Asset $asset): string => $asset->accession_number)->all());

            return app(AiDispatchService::class)->dispatchEmbeddingIndex($references, $provider, $user);
        });
    }
}
