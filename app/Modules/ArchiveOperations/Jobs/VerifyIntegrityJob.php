<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\IntegrityVerificationService;
use App\Modules\Catalogue\Models\AssetFile;

/**
 * Streams and re-hashes originals to detect missing or corrupt files.
 *
 * Reading every byte of a 32 MB scan is exactly the work that must not sit inside an
 * HTTP request, so verification advances one bounded chunk per job.
 */
class VerifyIntegrityJob extends OperationJob
{
    public const string TYPE = 'integrity.verify';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $cursor = is_string($payload['cursor'] ?? null) ? $payload['cursor'] : null;

        $files = AssetFile::query()
            ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        $service = app(IntegrityVerificationService::class);
        $processed = 0;
        $failed = 0;
        $lastId = $cursor;

        foreach ($files as $file) {
            $lastId = $file->id;
            $result = $service->verifyFile($file);
            $processed++;
            if ($result['status'] !== 'ok') {
                $failed++;
            }
        }

        $run->forceFill(['payload' => array_merge($payload, ['cursor' => $lastId])])->save();

        return [
            'processed' => $processed,
            'failed' => $failed,
            'finished' => $files->count() < self::CHUNK_SIZE,
            'result' => ['issues' => $run->failed_items + $failed],
        ];
    }
}
