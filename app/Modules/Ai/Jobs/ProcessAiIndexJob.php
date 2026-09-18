<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Services\AiBudgetLedgerService;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiIndexGenerationService;
use App\Modules\Ai\Services\AiOperationAuditService;
use App\Modules\Ai\Services\AiProviderConfigService;
use App\Modules\Ai\Services\AiProviderResolver;
use App\Modules\Ai\Services\AiSourceImageService;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessAiIndexJob extends OperationJob
{
    public const string TYPE = 'ai.index';

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
        $configuration = app(AiConfigurationService::class);
        $resolver = app(AiProviderResolver::class);
        $ledgerService = app(AiBudgetLedgerService::class);
        $sourceImages = app(AiSourceImageService::class);
        $audit = app(AiOperationAuditService::class);
        $vectors = app(PgvectorEmbeddingStore::class);
        $generations = app(AiIndexGenerationService::class);
        $generation = $generations->forRun($run);
        $isNative = in_array($provider, self::NATIVE_PROVIDERS, true);
        $costCents = $isNative ? app(AiProviderConfigService::class)->cost($provider, 'embeddings') : 0;

        if (! $configuration->cancelQueuedRunIfUnavailable($run, 'embeddings', $provider)) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true, 'result' => ['provider' => $provider, 'cancelled' => true]];
        }
        $vectors->requireAvailable();

        foreach ($slice as $assetId) {
            if ($this->shouldPause($run)) {
                break;
            }
            if (! $configuration->cancelQueuedRunIfUnavailable($run, 'embeddings', $provider)) {
                return ['processed' => $processed, 'processed_total' => $run->processed_items, 'failed' => 0, 'finished' => true, 'result' => ['provider' => $provider, 'cancelled' => true]];
            }
            $asset = null;
            $file = null;
            try {
                $candidate = Asset::query()->with('files')->whereKey($assetId)->first();
                if (! $candidate instanceof Asset) {
                    throw new RuntimeException(__('ai.errors.asset_missing', ['asset' => $assetId]));
                }
                $asset = $candidate;
                ['file' => $file, 'bytes' => $bytes] = $sourceImages->load($asset);
                $sourceLock = (int) $asset->lock_version;
                $sourceSha = (string) $file->sha256;
                $key = hash('sha256', implode('|', [self::TYPE, $run->id, $provider, $model, $file->id, $sourceSha, $sourceLock]));
                $completed = $generation !== null && AiRun::query()->where('idempotency_key', $key)->where('status', AiRun::STATUS_SUCCEEDED)->exists()
                    && $generation->embeddings()->where('asset_file_id', $file->id)->where('source_file_sha256', $sourceSha)
                        ->where('source_asset_lock_version', $sourceLock)->whereNull('stale_at')->exists();
                $embedding = null;
                if (! $completed) {
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
                    $generation ??= $generations->create($run, $provider, $model, $embedding['model_space'], $embedding['dimensions']);
                    $generations->assertCompatible($generation, $provider, $model, $embedding['model_space'], $embedding['dimensions']);
                }
                if ($generation === null) {
                    throw new RuntimeException(__('ai.errors.semantic_no_index'));
                }

                DB::transaction(function () use ($run, $asset, $file, $assetId, $sourceLock, $sourceSha, $key, $completed, $generation, $embedding, $provider, $model, $vectors, $audit, $cursor): void {
                    $currentAsset = Asset::query()->lockForUpdate()->whereKey($assetId)->first();
                    $currentFile = AssetFile::query()->lockForUpdate()->whereKey($file->id)->first();
                    if ($currentAsset === null || $currentFile === null || (int) $currentAsset->lock_version !== $sourceLock
                        || $currentFile->asset_id !== $assetId || $currentFile->sha256 !== $sourceSha
                        || ! $currentFile->is_primary || $currentFile->scanner_status !== 'clean' || $currentFile->ingest_status !== 'ready_private') {
                        throw new RuntimeException(__('ai.errors.asset_changed_index', ['asset' => $assetId]));
                    }
                    if (! $completed) {
                        $stored = AiEmbedding::query()->updateOrCreate([
                            'ai_embedding_generation_id' => $generation->id,
                            'asset_file_id' => $file->id,
                            'source_file_sha256' => $sourceSha,
                        ], [
                            'asset_id' => $assetId,
                            'source_asset_lock_version' => $sourceLock,
                            'embedding' => null,
                            'indexed_at' => now(),
                            'stale_at' => null,
                            'metadata' => ['provider' => $provider, 'modality' => 'image', 'source' => 'primary_file'],
                        ]);
                        $vectors->persist($stored, $embedding['embedding'] ?? throw new \LogicException(__('ai.errors.embedding_response_missing')));
                        AiRun::query()->firstOrCreate(['idempotency_key' => $key], [
                            'run_type' => AiRun::TYPE_EMBEDDING,
                            'status' => AiRun::STATUS_SUCCEEDED,
                            'asset_id' => $assetId,
                            'asset_file_id' => $file->id,
                            'source_asset_lock_version' => $sourceLock,
                            'source_file_sha256' => $sourceSha,
                            'provider_kind' => $provider,
                            'provider_name' => $generation->provider_name,
                            'model_id' => $generation->model_id,
                            'model_space' => $generation->model_space,
                            'requested_by_user_id' => $run->requested_by_user_id,
                            'input_contract' => ['modality' => 'image', 'metadata_stripped' => true, 'automatic_metadata_write' => false],
                            'result_summary' => ['dimensions' => $generation->dimensions, 'vector_backend' => 'pgvector', 'generation_id' => $generation->id],
                            'started_at' => now(),
                            'finished_at' => now(),
                        ]);
                        $audit->succeeded($run, $asset, $file, $provider, $model);
                    }
                    // Storage, receipt, cursor and count commit together. Redelivery
                    // never repeats a durably completed provider request or count.
                    $run->forceFill([
                        'payload' => array_merge($run->payload ?? [], ['cursor' => $cursor + 1, 'generation_id' => $generation->id, 'model_space' => $generation->model_space]),
                        'processed_items' => $cursor + 1,
                        'error_message' => null,
                    ])->save();
                });
                $cursor++;
                $processed++;
            } catch (Throwable $exception) {
                $run->refresh();
                if ($file === null && $asset instanceof Asset) {
                    $primaryFile = $asset->files->firstWhere('is_primary', true);
                    $file = $primaryFile instanceof AssetFile ? $primaryFile : null;
                }
                $audit->failed($run, $assetId, $asset, $file, $provider, $model, $exception);
                throw $exception;
            }
        }

        $finished = $cursor >= count($assetIds);
        if ($finished && $generation !== null) {
            $run->refresh();
            if ($run->status === OperationRun::STATUS_CANCELLED || ! $configuration->cancelQueuedRunIfUnavailable($run, 'embeddings', $provider)) {
                return ['processed' => $processed, 'processed_total' => $run->processed_items, 'failed' => 0, 'finished' => true, 'result' => ['provider' => $provider, 'cancelled' => true]];
            }
            try {
                $generations->activate($generation);
            } catch (Throwable $exception) {
                $audit->generation($run, $generation, $exception);
                throw $exception;
            }
        }

        return [
            'processed' => $processed,
            'processed_total' => $run->processed_items,
            'failed' => 0,
            'finished' => $finished,
            'result' => ['provider' => $provider, 'generation_id' => $generation?->id],
        ];
    }
}
