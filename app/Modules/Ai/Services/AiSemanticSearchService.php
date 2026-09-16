<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Publication\Models\Publication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AiSemanticSearchService
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
    ) {}

    /**
     * @return list<array{asset_id: string, accession_number: string|null, title: string|null, score: float, model_space: string}>
     */
    public function searchAdmin(string $query, string $provider, User $user, int $limit = 10): array
    {
        $limit = max(1, min($limit, 25));
        $ranked = $this->rankEmbeddings($query, $provider, 500);
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
        $matches = $this->rankEmbeddings($query, $provider, max(1, min($limit, 24)));
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
            ->values();
    }

    /**
     * @return list<array{asset_id: string, accession_number: string|null, title: string|null, score: float, model_space: string}>
     */
    private function rankEmbeddings(string $query, string $provider, int $limit): array
    {
        $query = trim($query);
        $settings = $this->configuration->effective();
        $errors = [];

        if ($query === '') {
            $errors['query'] = 'Vul een zoekvraag in.';
        }
        if (! (bool) ($settings['active'] ?? false) || ! (bool) ($settings['embeddings_enabled'] ?? false)) {
            $errors['ai'] = 'AI-embeddings zijn niet actief.';
        }
        $configuredProvider = (string) ($settings['embeddings_provider'] ?? '');
        if ($provider === '' || $provider !== $configuredProvider) {
            $errors['provider'] = 'De provider moet overeenkomen met de geconfigureerde embeddings-provider.';
        } elseif (! (bool) ($settings['embeddings_ready'] ?? false)) {
            $errors['provider'] = 'De geconfigureerde embeddings-provider is niet gereed (toestemming, model of budget ontbreekt).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $queryEmbedding = app(AiProviderResolver::class)->resolveEmbeddings($provider)
            ->embedText($query, ['model' => (string) ($settings['embeddings_model'] ?? '')]);

        $generation = AiEmbeddingGeneration::query()
            ->where('provider_kind', $provider)
            ->where('model_space', $queryEmbedding['model_space'])
            ->where('status', AiEmbeddingGeneration::STATUS_ACTIVE)
            ->first();
        if (! $generation instanceof AiEmbeddingGeneration) {
            throw ValidationException::withMessages([
                'query' => 'Er is geen actieve beeldindex voor het model_space van deze tekstquery.',
            ]);
        }
        if ((int) $generation->dimensions !== (int) $queryEmbedding['dimensions']) {
            throw ValidationException::withMessages([
                'query' => 'Tekstquery en beeldindex gebruiken verschillende embeddingdimensies.',
            ]);
        }

        $candidateLimit = 500;
        $matches = [];
        AiEmbedding::query()
            ->with('asset')
            ->where('ai_embedding_generation_id', $generation->id)
            ->whereNull('stale_at')
            ->latest('indexed_at')
            ->limit($candidateLimit)
            ->get()
            ->each(function (AiEmbedding $embedding) use (&$matches, $queryEmbedding, $generation): void {
                $asset = $embedding->asset;
                $imageEmbedding = $embedding->embedding;
                if ($asset === null || ! is_array($imageEmbedding)) {
                    return;
                }
                $score = $this->cosine($queryEmbedding['embedding'], array_values($imageEmbedding));
                if ($score <= 0.0) {
                    return;
                }
                $matches[] = [
                    'asset_id' => $asset->id,
                    'accession_number' => $asset->accession_number,
                    'title' => $asset->title,
                    'score' => round($score, 6),
                    'model_space' => $generation->model_space,
                ];
            });

        usort($matches, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    private function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $value) {
            $left = (float) $value;
            $right = (float) $b[$i];
            $dot += $left * $right;
            $normA += $left * $left;
            $normB += $right * $right;
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
