<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AiOperationAuditService
{
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
            'message' => 'AI-item succesvol verwerkt.',
            'context' => $context,
        ]);

        Log::info('AI operation item succeeded.', $context);
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

        Log::error('AI operation item failed.', array_merge($context, ['error' => $message]));
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
