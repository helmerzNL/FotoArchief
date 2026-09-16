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
        $configuredProvider = (string) ($settings['image_analysis_provider'] ?? '');
        if ($provider === '' || $provider !== $configuredProvider) {
            $errors['provider'] = 'De provider moet overeenkomen met de geconfigureerde beeldanalyse-provider.';
        } elseif (! (bool) ($settings['image_analysis_ready'] ?? false)) {
            $errors['provider'] = 'De geconfigureerde beeldanalyse-provider is niet gereed (toestemming, model of budget ontbreekt).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->runs->dispatchRun(ProcessAiAnalysisJob::class, ProcessAiAnalysisJob::TYPE, $user, [
            'asset_ids' => $assetIds,
            'provider' => $provider,
            'model' => (string) ($settings['image_analysis_model'] ?? ''),
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
        $configuredProvider = (string) ($settings['embeddings_provider'] ?? '');
        if ($provider === '' || $provider !== $configuredProvider) {
            $errors['provider'] = 'De provider moet overeenkomen met de geconfigureerde embeddings-provider.';
        } elseif (! (bool) ($settings['embeddings_ready'] ?? false)) {
            $errors['provider'] = 'De geconfigureerde embeddings-provider is niet gereed (toestemming, model of budget ontbreekt).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->runs->dispatchRun(ProcessAiIndexJob::class, ProcessAiIndexJob::TYPE, $user, [
            'asset_ids' => $assetIds,
            'provider' => $provider,
            'model' => (string) ($settings['embeddings_model'] ?? ''),
            'cursor' => 0,
        ], count($assetIds));
    }
}
