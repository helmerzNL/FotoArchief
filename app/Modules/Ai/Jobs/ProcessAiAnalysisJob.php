<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Ai\Services\ExternalAiProvider;
use App\Modules\Ai\Services\LocalAiProvider;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessAiAnalysisJob extends OperationJob
{
    public const string TYPE = 'ai.analysis';

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

            $analysis = $provider === 'external'
                ? app(ExternalAiProvider::class)->analyzeImage($bytes, ['asset_id' => $asset->id, 'asset_file_id' => $file->id])
                : app(LocalAiProvider::class)->analyzeImage($bytes, ['asset_id' => $asset->id, 'asset_file_id' => $file->id]);

            $asset->refresh();
            $file->refresh()->load('asset');
            if ((int) $asset->lock_version !== $sourceLock || (string) $file->sha256 !== $sourceSha) {
                $failed++;

                continue;
            }

            $aiRun = AiRun::query()->firstOrCreate(
                ['idempotency_key' => $this->idempotencyKey($provider, $file)],
                [
                    'run_type' => AiRun::TYPE_IMAGE_ANALYSIS,
                    'status' => AiRun::STATUS_SUCCEEDED,
                    'asset_id' => $asset->id,
                    'asset_file_id' => $file->id,
                    'source_asset_lock_version' => $sourceLock,
                    'source_file_sha256' => $sourceSha,
                    'provider_kind' => $provider,
                    'provider_name' => $provider === 'external' ? 'external-http' : 'owned-http',
                    'model_id' => (string) ($analysis['model_id'] ?? 'unreported'),
                    'model_version' => is_string($analysis['model_version'] ?? null) ? $analysis['model_version'] : null,
                    'model_space' => is_string($analysis['model_space'] ?? null) ? $analysis['model_space'] : null,
                    'input_contract' => [
                        'derivative' => 'configured-ai-derivative',
                        'metadata_stripped' => true,
                        'automatic_metadata_write' => false,
                    ],
                    'result_summary' => ['suggestion_count' => 0],
                    'started_at' => now(),
                    'finished_at' => now(),
                ],
            );

            $suggestions = $this->storeSuggestions($aiRun, $asset, $file, $analysis, $sourceLock, $sourceSha);
            $aiRun->forceFill(['result_summary' => ['suggestion_count' => $suggestions]])->save();
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

    private function idempotencyKey(string $provider, AssetFile $file): string
    {
        return hash('sha256', implode('|', [self::TYPE, $provider, $file->id, $file->sha256]));
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function storeSuggestions(AiRun $run, Asset $asset, AssetFile $file, array $analysis, int $sourceLock, string $sourceSha): int
    {
        $count = 0;
        if (is_string($analysis['description'] ?? null)) {
            AiSuggestion::query()->firstOrCreate([
                'ai_run_id' => $run->id,
                'suggestion_type' => AiSuggestion::TYPE_DESCRIPTION,
                'value' => $analysis['description'],
            ], [
                'asset_id' => $asset->id,
                'asset_file_id' => $file->id,
                'source_asset_lock_version' => $sourceLock,
                'source_file_sha256' => $sourceSha,
                'confidence' => is_numeric($analysis['confidence'] ?? null) ? (float) $analysis['confidence'] : null,
                'evidence' => ['source' => 'image-analysis'],
            ]);
            $count++;
        }
        foreach (array_slice(array_filter($analysis['tags'] ?? [], 'is_string'), 0, 20) as $tag) {
            AiSuggestion::query()->firstOrCreate([
                'ai_run_id' => $run->id,
                'suggestion_type' => AiSuggestion::TYPE_TAG,
                'value' => Str::lower($tag),
            ], [
                'asset_id' => $asset->id,
                'asset_file_id' => $file->id,
                'source_asset_lock_version' => $sourceLock,
                'source_file_sha256' => $sourceSha,
                'evidence' => ['source' => 'image-analysis'],
            ]);
            $count++;
        }

        return $count;
    }
}
