<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\IntegrityCheck;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\IntegrityVerificationService;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * Regenerates missing JPEG previews from the immutable original.
 *
 * Decoding and re-encoding full-resolution scans is unbounded in an HTTP request;
 * here each job rebuilds at most CHUNK_SIZE files and records per-file failures.
 */
class RebuildDerivativesJob extends OperationJob
{
    public const string TYPE = 'integrity.rebuild';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $actor = User::query()->find($run->requested_by_user_id);
        if (! $actor instanceof User) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true];
        }

        $explicitFileId = is_string($payload['asset_file_id'] ?? null) ? $payload['asset_file_id'] : null;

        $fileIds = $explicitFileId !== null
            ? collect([$explicitFileId])
            : IntegrityCheck::query()
                ->where('status', 'missing_derivative')
                ->whereNull('resolved_at')
                ->orderBy('asset_file_id')
                ->pluck('asset_file_id')
                ->unique()
                ->take(self::CHUNK_SIZE);

        $service = app(IntegrityVerificationService::class);
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($fileIds as $fileId) {
            $file = AssetFile::query()->find($fileId);
            if (! $file instanceof AssetFile) {
                continue;
            }

            try {
                $service->rebuildMissingDerivatives($file, $actor);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $errors[$fileId] = Str::limit($exception->getMessage(), 300);
            }
        }

        // An explicit single-file rebuild is always one chunk; a sweep is finished once
        // no unresolved missing_derivative rows remain or nothing could be repaired.
        $finished = $explicitFileId !== null
            || $fileIds->count() < self::CHUNK_SIZE
            || $processed === 0;

        return [
            'processed' => $processed,
            'failed' => $failed,
            'finished' => $finished,
            'result' => $errors === [] ? [] : ['errors' => $errors],
        ];
    }
}
