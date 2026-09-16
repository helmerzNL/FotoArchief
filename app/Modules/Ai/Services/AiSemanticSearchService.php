<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
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
        $query = trim($query);
        $limit = max(1, min($limit, 25));
        $settings = $this->configuration->effective();
        $errors = [];

        if ($query === '') {
            $errors['query'] = 'Vul een zoekvraag in.';
        }
        if (! (bool) ($settings['active'] ?? false) || ! (bool) ($settings['embeddings_enabled'] ?? false)) {
            $errors['ai'] = 'AI-embeddings zijn niet actief.';
        }
        if ($provider === 'local' && ! (bool) ($settings['local_ready'] ?? false)) {
            $errors['provider'] = 'Lokale AI-provider is niet gereed.';
        }
        if ($provider === 'external' && ! (bool) ($settings['external_ready'] ?? false)) {
            $errors['provider'] = 'Externe AI-provider is niet gereed of niet expliciet toegestaan.';
        }
        if (! in_array($provider, ['local', 'external'], true)) {
            $errors['provider'] = 'Kies local of external als AI-provider.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $queryEmbedding = $provider === 'external'
            ? app(ExternalAiProvider::class)->embedText($query)
            : app(LocalAiProvider::class)->embedText($query);

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
            ->each(function (AiEmbedding $embedding) use (&$matches, $queryEmbedding, $user, $generation): void {
                $asset = $embedding->asset;
                if ($asset === null || ! Gate::forUser($user)->allows('view', $asset)) {
                    return;
                }
                $score = $this->cosine($queryEmbedding['embedding'], $embedding->embedding ?? []);
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
