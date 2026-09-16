<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Services\ExternalAiProvider;
use App\Modules\Ai\Services\LocalAiProvider;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Storage;

class ProcessAiIndexJob extends OperationJob
{
    public const string TYPE = 'ai.index';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $assetIds = array_values(array_filter($payload['asset_ids'] ?? [], 'is_string'));
        $provider = (string) ($payload['provider'] ?? 'local');
        $cursor = (int) ($payload['cursor'] ?? 0);
        $slice = array_slice($assetIds, $cursor, self::CHUNK_SIZE);
        $processed = 0;
        $failed = 0;

        foreach ($slice as $assetId) {
            $asset = Asset::query()->with('files')->find($assetId);
            $file = $asset instanceof Asset ? $this->primaryFile($asset) : null;
            if (! $asset instanceof Asset || ! $file instanceof AssetFile) {
                $failed++;

                continue;
            }

            $sourceLock = (int) $asset->lock_version;
            $sourceSha = (string) $file->sha256;
            $disk = Storage::disk((string) ($file->storage_disk ?: config('filesystems.default')));
            $bytes = $disk->get($file->storage_key);
            if (! is_string($bytes)) {
                $failed++;

                continue;
            }

            $embedding = $provider === 'external'
                ? app(ExternalAiProvider::class)->embedImage($bytes)
                : app(LocalAiProvider::class)->embedImage($bytes);

            $asset->refresh();
            $file->refresh()->load('asset');
            if ((int) $asset->lock_version !== $sourceLock || (string) $file->sha256 !== $sourceSha) {
                $failed++;

                continue;
            }

            $generation = AiEmbeddingGeneration::query()->firstOrCreate(
                ['model_space' => $embedding['model_space']],
                [
                    'provider_kind' => $provider,
                    'provider_name' => $provider === 'external' ? 'external-http' : 'owned-http',
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

            $generation->embeddings()
                ->where('asset_file_id', $file->id)
                ->whereNull('stale_at')
                ->where('source_file_sha256', '!=', $sourceSha)
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
                    'provider_name' => $provider === 'external' ? 'external-http' : 'owned-http',
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

    private function primaryFile(Asset $asset): ?AssetFile
    {
        return $asset->files
            ->where('is_primary', true)
            ->where('ingest_status', 'ready_private')
            ->where('scanner_status', 'clean')
            ->first();
    }

    private function idempotencyKey(string $provider, AssetFile $file, AiEmbeddingGeneration $generation): string
    {
        return hash('sha256', implode('|', [self::TYPE, $provider, $generation->model_space, $file->id, $file->sha256]));
    }
}
