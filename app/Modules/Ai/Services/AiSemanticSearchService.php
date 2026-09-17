<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Publication\Models\Publication;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AiSemanticSearchService
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly PgvectorEmbeddingStore $vectors,
    ) {}

    /**
     * @return list<array{asset_id: string, accession_number: string|null, title: string|null, score: float, model_space: string}>
     */
    public function searchAdmin(string $query, string $provider, User $user, int $limit = 10): array
    {
        $limit = max(1, min($limit, 25));
        $eligibleAssets = Asset::query()->select('assets.id');
        if (! $user->hasPermission('assets.view')) {
            $eligibleAssets->whereRaw('1 = 0');
        } elseif (! $user->hasPermission('assets.publish')) {
            $eligibleAssets->where('created_by_user_id', $user->id);
        }
        $ranked = $this->rankEmbeddings($query, $provider, $limit, $eligibleAssets->toBase());
        $assets = Asset::query()->whereIn('id', array_column($ranked, 'asset_id'))->get()->keyBy('id');
        $matches = array_filter($ranked, function (array $match) use ($assets, $user): bool {
            $asset = $assets->get($match['asset_id']);

            return $asset instanceof Asset && Gate::forUser($user)->allows('view', $asset);
        });

        usort($matches, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * @return Collection<int, Publication>
     */
    public function searchPublic(string $query, string $provider, int $limit = 24): Collection
    {
        $limit = max(1, min($limit, 24));
        $eligibleAssets = Publication::query()->publiclyVisible()->select('publications.asset_id')->toBase();
        $matches = $this->rankEmbeddings($query, $provider, $limit, $eligibleAssets);
        if ($matches === []) {
            return collect();
        }

        $assetIds = array_column($matches, 'asset_id');
        $rank = array_flip($assetIds);

        return Publication::query()
            ->publiclyVisible()
            ->with(['asset.files'])
            ->whereIn('asset_id', $assetIds)
            ->get()
            ->sortBy(fn (Publication $publication): int => $rank[$publication->asset_id] ?? PHP_INT_MAX)
            ->values()
            ->take($limit)
            ->values();
    }

    /**
     * @return list<array{asset_id: string, accession_number: string|null, title: string|null, score: float, model_space: string}>
     */
    private function rankEmbeddings(string $query, string $provider, int $limit, Builder $eligibleAssets): array
    {
        $query = trim($query);
        $settings = $this->configuration->effective();
        $errors = [];

        if ($query === '') {
            $errors['query'] = __('ai.errors.semantic_query_required');
        }
        if (! (bool) ($settings['active'] ?? false) || ! (bool) ($settings['embeddings_enabled'] ?? false)) {
            $errors['ai'] = __('ai.errors.embeddings_inactive');
        }
        $configuredProvider = (string) ($settings['embeddings_provider'] ?? '');
        if ($provider === '' || $provider !== $configuredProvider) {
            $errors['provider'] = __('ai.errors.embeddings_provider_mismatch');
        } elseif (! (bool) ($settings['embeddings_ready'] ?? false)) {
            $errors['provider'] = __('ai.errors.embeddings_provider_not_ready');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->vectors->requireAvailable();
        $generation = app(AiIndexGenerationService::class)->activeForProvider($provider);
        if ($generation === null || $generation->requested_model !== (string) ($settings['embeddings_model'] ?? '')) {
            throw ValidationException::withMessages(['query' => __('ai.errors.semantic_no_index')]);
        }

        $queryEmbedding = app(AiProviderResolver::class)->resolveEmbeddings($provider)
            ->embedText($query, ['model' => (string) ($settings['embeddings_model'] ?? '')]);

        if ($generation->model_space !== $queryEmbedding['model_space']) {
            throw ValidationException::withMessages([
                'query' => __('ai.errors.semantic_no_index'),
            ]);
        }
        if ((int) $generation->dimensions !== (int) $queryEmbedding['dimensions']) {
            throw ValidationException::withMessages([
                'query' => __('ai.errors.semantic_dimension_mismatch'),
            ]);
        }

        return array_values(array_filter(
            $this->vectors->nearest($generation, $queryEmbedding['embedding'], $limit, $eligibleAssets),
            static fn (array $match): bool => $match['score'] > 0.0,
        ));
    }
}
