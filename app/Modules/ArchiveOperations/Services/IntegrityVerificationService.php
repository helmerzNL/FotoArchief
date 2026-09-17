<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\IntegrityCheck;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class IntegrityVerificationService
{
    public function __construct(
        private readonly FileVersionService $fileVersionService,
    ) {}

    /**
     * @return array{total_files: int, ok: int, missing_original: int, corrupt_checksum: int, missing_derivative: int}
     */
    public function getIntegritySummary(): array
    {
        $totalFiles = AssetFile::query()->count();
        $openIssues = IntegrityCheck::query()->whereNull('resolved_at')->get();

        $missingOriginal = $openIssues->where('status', 'missing_original')->count();
        $corruptChecksum = $openIssues->where('status', 'corrupt_checksum')->count();
        $missingDerivative = $openIssues->where('status', 'missing_derivative')->count();
        $okCount = max(0, $totalFiles - $missingOriginal - $corruptChecksum - $missingDerivative);

        return [
            'total_files' => $totalFiles,
            'ok' => $okCount,
            'missing_original' => $missingOriginal,
            'corrupt_checksum' => $corruptChecksum,
            'missing_derivative' => $missingDerivative,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, IntegrityCheck>
     */
    public function getOpenIssues(int $perPage = 25): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, IntegrityCheck> $results */
        $results = IntegrityCheck::query()
            ->with(['asset', 'file'])
            ->whereNull('resolved_at')
            ->where('status', '!=', 'ok')
            ->latest('id')
            ->paginate($perPage);

        return $results;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function verifyFile(AssetFile $file): array
    {
        $disk = Storage::disk($file->storage_disk);

        // 1. Check original presence
        if (! $disk->exists($file->storage_key)) {
            $this->recordCheck($file, 'missing_original', null, ['message' => __('operations.generated.t_a1c0ebe48b014473')]);

            return ['status' => 'missing_original', 'message' => __('operations.generated.t_9df71e954270244e')];
        }

        // 2. Read stream and verify SHA-256
        $stream = $disk->readStream($file->storage_key);
        if (! is_resource($stream)) {
            $this->recordCheck($file, 'missing_original', null, ['message' => __('operations.generated.t_58f30d4027ae9217')]);

            return ['status' => 'missing_original', 'message' => __('operations.generated.t_58f30d4027ae9217')];
        }

        $ctx = hash_init('sha256');
        while (! feof($stream)) {
            $buffer = fread($stream, 65536);
            if ($buffer === false) {
                break;
            }
            hash_update($ctx, $buffer);
        }
        fclose($stream);
        $actualSha = hash_final($ctx);

        if ($actualSha !== $file->sha256) {
            $this->recordCheck($file, 'corrupt_checksum', $actualSha, [
                'expected' => $file->sha256,
                'actual' => $actualSha,
                'message' => __('operations.generated.t_1291e48aec6f6025'),
            ]);

            return ['status' => 'corrupt_checksum', 'message' => __('operations.generated.t_023f1b311e5d884a')];
        }

        // 3. Check derivatives presence
        $derivatives = (array) ($file->derivatives ?? []);
        $missingDerivatives = [];
        foreach (['preview300', 'preview1200', 'preview2000'] as $size) {
            $key = $derivatives[$size] ?? null;
            if (! is_string($key) || ! $disk->exists($key)) {
                $missingDerivatives[] = $size;
            }
        }

        if ($missingDerivatives !== []) {
            $this->recordCheck($file, 'missing_derivative', $actualSha, [
                'missing' => $missingDerivatives,
                'message' => __('operations.generated.t_6960268775f04bad'),
            ]);

            return ['status' => 'missing_derivative', 'message' => __('operations.generated.t_1be9b78445afc873')];
        }

        // 4. Clean pass
        $this->resolvePriorIssues($file);
        $this->recordCheck($file, 'ok', $actualSha, ['message' => __('operations.generated.t_747693d523f64f75')]);

        return ['status' => 'ok', 'message' => __('operations.generated.t_86090dbe86093a9d')];
    }

    /**
     * @return array{total_checked: int, ok: int, issues: int}
     */
    public function verifyAll(?int $limit = 500): array
    {
        $files = AssetFile::query()->limit($limit ?? 500)->get();
        $checked = 0;
        $ok = 0;
        $issues = 0;

        foreach ($files as $file) {
            $result = $this->verifyFile($file);
            $checked++;
            if ($result['status'] === 'ok') {
                $ok++;
            } else {
                $issues++;
            }
        }

        return [
            'total_checked' => $checked,
            'ok' => $ok,
            'issues' => $issues,
        ];
    }

    public function rebuildMissingDerivatives(AssetFile $file, User $actor): void
    {
        $this->fileVersionService->reprocessDerivatives($file, $actor);

        // Mark missing_derivative checks as resolved
        IntegrityCheck::query()
            ->where('asset_file_id', $file->id)
            ->where('status', 'missing_derivative')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        AssetAuditEvent::query()->create([
            'asset_id' => $file->asset_id,
            'actor_user_id' => $actor->id,
            'event_type' => 'integrity.derivatives_rebuilt',
            'details' => ['file_id' => $file->id],
        ]);
    }

    public function rebuildAllMissingDerivatives(User $actor): int
    {
        /** @var Collection<int, string> $fileIds */
        $fileIds = IntegrityCheck::query()
            ->where('status', 'missing_derivative')
            ->whereNull('resolved_at')
            ->pluck('asset_file_id')
            ->unique();

        $rebuiltCount = 0;
        foreach ($fileIds as $fileId) {
            $file = AssetFile::query()->find($fileId);
            if ($file instanceof AssetFile) {
                try {
                    $this->rebuildMissingDerivatives($file, $actor);
                    $rebuiltCount++;
                } catch (Throwable) {
                    // Continue with other files
                }
            }
        }

        return $rebuiltCount;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function recordCheck(AssetFile $file, string $status, ?string $actualSha, array $details): void
    {
        IntegrityCheck::query()->create([
            'asset_id' => $file->asset_id,
            'asset_file_id' => $file->id,
            'check_type' => 'full',
            'status' => $status,
            'expected_sha256' => $file->sha256,
            'actual_sha256' => $actualSha,
            'details' => $details,
            'resolved_at' => $status === 'ok' ? now() : null,
        ]);
    }

    private function resolvePriorIssues(AssetFile $file): void
    {
        IntegrityCheck::query()
            ->where('asset_file_id', $file->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }
}
