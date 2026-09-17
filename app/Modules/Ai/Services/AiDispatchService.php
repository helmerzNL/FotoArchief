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
        private readonly AiAssetBatchService $batches,
        private readonly PgvectorEmbeddingStore $vectors,
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
            $errors['ai'] = __('ai.errors.analysis_inactive');
        }
        if ($assetIds === [] || count($assetIds) > $limit) {
            $errors['asset_ids'] = __('ai.errors.asset_batch_count', ['limit' => $limit]);
        }
        $configuredProvider = (string) ($settings['image_analysis_provider'] ?? '');
        if ($provider === '' || $provider !== $configuredProvider) {
            $errors['provider'] = __('ai.errors.analysis_provider_mismatch');
        } elseif (! (bool) ($settings['image_analysis_ready'] ?? false)) {
            $errors['provider'] = __('ai.errors.analysis_provider_not_ready');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $assetIds = $this->batches->normalize($assetIds, $user)['asset_ids'];

        return $this->runs->dispatchRun(ProcessAiAnalysisJob::class, ProcessAiAnalysisJob::TYPE, $user, [
            'asset_ids' => $assetIds,
            'asset_id_format' => AiAssetBatchService::INTERNAL_FORMAT,
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
            $errors['ai'] = __('ai.errors.embeddings_inactive');
        }
        if ($assetIds === [] || count($assetIds) > $limit) {
            $errors['asset_ids'] = __('ai.errors.index_batch_count', ['limit' => $limit]);
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

        $assetIds = $this->batches->normalize($assetIds, $user)['asset_ids'];

        $this->vectors->requireAvailable();

        return $this->runs->dispatchRun(ProcessAiIndexJob::class, ProcessAiIndexJob::TYPE, $user, [
            'asset_ids' => $assetIds,
            'asset_id_format' => AiAssetBatchService::INTERNAL_FORMAT,
            'provider' => $provider,
            'model' => (string) ($settings['embeddings_model'] ?? ''),
            'cursor' => 0,
        ], count($assetIds));
    }
}
