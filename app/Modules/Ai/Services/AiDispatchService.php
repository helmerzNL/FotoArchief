<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use Illuminate\Validation\ValidationException;

class AiDispatchService
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly OperationRunService $runs,
    ) {}

    /**
     * @param  list<string>  $assetIds
     */
    public function dispatchImageAnalysis(array $assetIds, string $provider, User $user): OperationRun
    {
        $settings = $this->configuration->effective();
        $limit = (int) ($settings['max_assets_per_batch'] ?? 25);
        $assetIds = array_values(array_unique($assetIds));
        $errors = [];

        if (! (bool) ($settings['active'] ?? false) || ! (bool) ($settings['image_analysis_enabled'] ?? false)) {
            $errors['ai'] = 'AI-beeldanalyse is niet actief.';
        }
        if ($assetIds === [] || count($assetIds) > $limit) {
            $errors['asset_ids'] = "Selecteer 1 tot {$limit} assets voor een AI-batch.";
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

        return $this->runs->dispatchRun(ProcessAiAnalysisJob::class, ProcessAiAnalysisJob::TYPE, $user, [
            'asset_ids' => $assetIds,
            'provider' => $provider,
            'cursor' => 0,
        ], count($assetIds));
    }

    /**
     * @param  list<string>  $assetIds
     */
    public function dispatchEmbeddingIndex(array $assetIds, string $provider, User $user): OperationRun
    {
        $settings = $this->configuration->effective();
        $limit = (int) ($settings['max_assets_per_batch'] ?? 25);
        $assetIds = array_values(array_unique($assetIds));
        $errors = [];

        if (! (bool) ($settings['active'] ?? false) || ! (bool) ($settings['embeddings_enabled'] ?? false)) {
            $errors['ai'] = 'AI-embeddings zijn niet actief.';
        }
        if ($assetIds === [] || count($assetIds) > $limit) {
            $errors['asset_ids'] = "Selecteer 1 tot {$limit} assets voor een AI-indexbatch.";
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

        return $this->runs->dispatchRun(ProcessAiIndexJob::class, ProcessAiIndexJob::TYPE, $user, [
            'asset_ids' => $assetIds,
            'provider' => $provider,
            'cursor' => 0,
        ], count($assetIds));
    }
}
