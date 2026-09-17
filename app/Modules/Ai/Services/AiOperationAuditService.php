<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AiOperationAuditService
{
    public function generation(OperationRun $run, AiEmbeddingGeneration $generation, ?Throwable $exception = null): void
    {
        $severity = $exception === null ? 'info' : 'error';
        $message = $exception === null ? __('ai.audit.generation_activated') : Str::limit($exception->getMessage(), 2000, '');
        $context = array_merge($this->context($run, null, $generation->provider_kind, $generation->requested_model), [
            'generation_id' => $generation->id,
            'base_generation_id' => $generation->base_generation_id,
            'model_space' => $generation->model_space,
            'dimensions' => $generation->dimensions,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
        OperationRunAuditEvent::query()->create([
            'operation_run_id' => $run->id,
            'event_type' => 'ai.index.'.($exception === null ? 'generation_activated' : 'generation_failed'),
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ]);
        Log::log($severity, $message, $context);
    }

    public function succeeded(
        OperationRun $run,
        Asset $asset,
        AssetFile $file,
        string $provider,
        string $model,
    ): void {
        $context = $this->context($run, $file, $provider, $model);

        OperationRunAuditEvent::query()->create([
            'operation_run_id' => $run->id,
            'asset_id' => $asset->id,
            'event_type' => $run->operation_type.'.item_succeeded',
            'severity' => 'info',
            'message' => __('ai.audit.item_succeeded'),
            'context' => $context,
        ]);

        Log::info(__('ai.audit.log_succeeded'), $context);
    }

    public function failed(
        OperationRun $run,
        string $assetId,
        ?Asset $asset,
        ?AssetFile $file,
        string $provider,
        string $model,
        Throwable $exception,
    ): void {
        $context = array_merge($this->context($run, $file, $provider, $model), [
            'asset_id' => $assetId,
            'exception_class' => $exception::class,
        ]);
        $message = Str::limit($exception->getMessage(), 2000, '');

        OperationRunAuditEvent::query()->create([
            'operation_run_id' => $run->id,
            'asset_id' => $asset?->id,
            'event_type' => $run->operation_type.'.item_failed',
            'severity' => 'error',
            'message' => $message,
            'context' => $context,
        ]);

        Log::error(__('ai.audit.log_failed'), array_merge($context, ['error' => $message]));
    }

    /**
     * @return array<string, int|string|null>
     */
    private function context(OperationRun $run, ?AssetFile $file, string $provider, string $model): array
    {
        return [
            'operation_run_id' => $run->id,
            'operation_type' => $run->operation_type,
            'attempt' => $run->attempts,
            'provider' => $provider,
            'model' => $model !== '' ? $model : null,
            'asset_file_id' => $file?->id,
            'scanner_status' => $file?->scanner_status,
            'source_sha256' => $file?->sha256,
        ];
    }
}
