<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessAiAnalysisJob extends OperationJob
{
    public const string TYPE = 'ai.analysis';

    /** Providers billed via the per-request budget ledger; local/external are not natively metered here. */
    private const NATIVE_PROVIDERS = ['openai', 'anthropic', 'gemini', 'openrouter'];

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
        $costCents = $isNative ? $providerConfigs->cost($provider, 'image_analysis') : 0;

        if (! app(AiConfigurationService::class)->cancelQueuedRunIfUnavailable($run, 'image_analysis', $provider)) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true, 'result' => ['provider' => $provider, 'cancelled' => true]];
        }

        foreach ($slice as $assetId) {
            if (! app(AiConfigurationService::class)->cancelQueuedRunIfUnavailable($run, 'image_analysis', $provider)) {
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

                $ledger = $isNative && $costCents > 0 ? $ledgerService->reserve($provider, 'image_analysis', $costCents) : null;
                try {
                    $analysis = $resolver->resolveImageAnalysis($provider)->analyzeImage($bytes, [
                        'asset_id' => $asset->id,
                        'asset_file_id' => $file->id,
                        'model' => $model,
                    ]);
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
                    throw new RuntimeException("Foto {$assetId} is tijdens de AI-analyse gewijzigd. Probeer de taak opnieuw.");
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
                        'provider_name' => $isNative ? "native-{$provider}" : ($provider === 'external' ? 'external-http' : 'owned-http'),
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
