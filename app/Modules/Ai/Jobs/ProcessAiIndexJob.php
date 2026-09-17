<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Services\AiBudgetLedgerService;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiOperationAuditService;
use App\Modules\Ai\Services\AiProviderConfigService;
use App\Modules\Ai\Services\AiProviderResolver;
use App\Modules\Ai\Services\AiSourceImageService;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

class ProcessAiIndexJob extends OperationJob
{
    public const string TYPE = 'ai.index';

    /** Providers billed via the per-request budget ledger; local/external are not natively metered here. */
    private const NATIVE_PROVIDERS = ['gemini', 'openrouter'];

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $assetIds = array_values(array_filter($payload['asset_ids'] ?? [], 'is_string'));
        $provider = (string) ($payload['provider'] ?? 'local');
        $model = (string) ($payload['model'] ?? '');
        $cursor = (int) ($payload['cursor'] ?? 0);
        $slice = array_slice($assetIds, $cursor, self::CHUNK_SIZE);
        $processed = 0;
        $failed = 0;
        $resolver = app(AiProviderResolver::class);
        $ledgerService = app(AiBudgetLedgerService::class);
        $providerConfigs = app(AiProviderConfigService::class);
        $sourceImages = app(AiSourceImageService::class);
        $audit = app(AiOperationAuditService::class);
        $isNative = in_array($provider, self::NATIVE_PROVIDERS, true);
        $costCents = $isNative ? $providerConfigs->cost($provider, 'embeddings') : 0;

        if (! app(AiConfigurationService::class)->cancelQueuedRunIfUnavailable($run, 'embeddings', $provider)) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true, 'result' => ['provider' => $provider, 'cancelled' => true]];
        }

        foreach ($slice as $assetId) {
            if (! app(AiConfigurationService::class)->cancelQueuedRunIfUnavailable($run, 'embeddings', $provider)) {
                return ['processed' => $processed, 'failed' => $failed, 'finished' => true, 'result' => ['provider' => $provider, 'cancelled' => true]];
            }
            $asset = null;
            $file = null;
            try {
                $candidate = Asset::query()->with('files')->find($assetId);
                if (! $candidate instanceof Asset) {
                    throw new RuntimeException("Foto {$assetId} bestaat niet meer.");
                }
                $asset = $candidate;
                ['file' => $file, 'bytes' => $bytes] = $sourceImages->load($asset);

                $sourceLock = (int) $asset->lock_version;
                $sourceSha = (string) $file->sha256;

                $ledger = $isNative && $costCents > 0 ? $ledgerService->reserve($provider, 'embeddings', $costCents) : null;
                try {
                    $embedding = $resolver->resolveEmbeddings($provider)->embedImage($bytes, ['model' => $model]);
                } catch (Throwable $exception) {
                    if ($ledger !== null) {
                        $ledgerService->release($ledger, $costCents);
                    }

                    throw $exception;
                }
                if ($ledger !== null) {
                    $ledgerService->consume($ledger, $costCents, $costCents);
                }

                $asset->refresh();
                $file->refresh()->load('asset');
                if ((int) $asset->lock_version !== $sourceLock || (string) $file->sha256 !== $sourceSha) {
                    throw new RuntimeException("Foto {$assetId} is tijdens de AI-indexering gewijzigd. Probeer de taak opnieuw.");
                }

                $generation = AiEmbeddingGeneration::query()->firstOrCreate(
                    ['model_space' => $embedding['model_space']],
                    [
                        'provider_kind' => $provider,
                        'provider_name' => $isNative ? "native-{$provider}" : ($provider === 'external' ? 'external-http' : 'owned-http'),
                        'model_id' => (string) $embedding['model_space'],
                        'dimensions' => $embedding['dimensions'],
                        'distance_metric' => 'cosine',
                        'vector_backend' => 'database_json',
                        'status' => AiEmbeddingGeneration::STATUS_ACTIVE,
                        'activated_at' => now(),
                        'capability_receipt' => [
                            'image_embeddings' => true,
                            'text_embeddings_required_for_queries' => true,
                            'same_embedding_space' => true,
                        ],
                    ],
                );

                if ((string) $generation->provider_kind !== $provider
                    || (int) $generation->dimensions !== (int) $embedding['dimensions']
                    || (string) $generation->status !== AiEmbeddingGeneration::STATUS_ACTIVE) {
                    throw new RuntimeException("AI-indexering geweigerd: modelruimte {$embedding['model_space']} hoort bij een andere provider, dimensie of status.");
                }

                $generation->embeddings()
                    ->where('asset_file_id', $file->id)
                    ->whereNull('stale_at')
                    ->where(function (Builder $query) use ($sourceLock, $sourceSha): void {
                        $query->where('source_file_sha256', '!=', $sourceSha)
                            ->orWhere('source_asset_lock_version', '!=', $sourceLock);
                    })
                    ->update(['stale_at' => now()]);

                AiEmbedding::query()->updateOrCreate(
                    [
                        'ai_embedding_generation_id' => $generation->id,
                        'asset_file_id' => $file->id,
                        'source_file_sha256' => $sourceSha,
                    ],
                    [
                        'asset_id' => $asset->id,
                        'source_asset_lock_version' => $sourceLock,
                        'embedding' => array_map('floatval', $embedding['embedding']),
                        'external_vector_id' => null,
                        'indexed_at' => now(),
                        'stale_at' => null,
                        'metadata' => [
                            'provider' => $provider,
                            'modality' => 'image',
                            'source' => 'primary_file',
                        ],
                    ],
                );

                AiRun::query()->firstOrCreate(
                    ['idempotency_key' => $this->idempotencyKey($provider, $file, $generation)],
                    [
                        'run_type' => AiRun::TYPE_EMBEDDING,
                        'status' => AiRun::STATUS_SUCCEEDED,
                        'asset_id' => $asset->id,
                        'asset_file_id' => $file->id,
                        'source_asset_lock_version' => $sourceLock,
                        'source_file_sha256' => $sourceSha,
                        'provider_kind' => $provider,
                        'provider_name' => $isNative ? "native-{$provider}" : ($provider === 'external' ? 'external-http' : 'owned-http'),
                        'model_id' => $generation->model_id,
                        'model_space' => $generation->model_space,
                        'input_contract' => [
                            'modality' => 'image',
                            'metadata_stripped' => true,
                            'automatic_metadata_write' => false,
                        ],
                        'result_summary' => [
                            'dimensions' => $embedding['dimensions'],
                            'vector_backend' => $generation->vector_backend,
                        ],
                        'started_at' => now(),
                        'finished_at' => now(),
                    ],
                );
                $processed++;
                $audit->succeeded($run, $asset, $file, $provider, $model);
            } catch (Throwable $exception) {
                if ($file === null && $asset instanceof Asset) {
                    $primaryFile = $asset->files->firstWhere('is_primary', true);
                    $file = $primaryFile instanceof AssetFile ? $primaryFile : null;
                }
                $audit->failed($run, $assetId, $asset, $file, $provider, $model, $exception);

                throw $exception;
            }
        }

        $nextCursor = $cursor + count($slice);
        $run->forceFill(['payload' => array_merge($payload, ['cursor' => $nextCursor])])->save();

        return [
            'processed' => $processed,
            'failed' => $failed,
            'finished' => $nextCursor >= count($assetIds),
            'result' => ['provider' => $provider],
        ];
    }

    private function idempotencyKey(string $provider, AssetFile $file, AiEmbeddingGeneration $generation): string
    {
        return hash('sha256', implode('|', [self::TYPE, $provider, $generation->model_space, $file->id, $file->sha256]));
    }
}
