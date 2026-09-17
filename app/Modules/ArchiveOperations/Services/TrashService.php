<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\TrashPurgeLog;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TrashService
{
    public const int DEFAULT_RETENTION_DAYS = 30;

    /**
     * @return array{
     *     trashed_count: int,
     *     expired_count: int,
     *     retention_days: int,
     *     orphan_uploads_count: int,
     * }
     */
    public function getTrashSummary(int $retentionDays = self::DEFAULT_RETENTION_DAYS): array
    {
        $trashedCount = Asset::onlyTrashed()->count();
        $expiredCount = Asset::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($retentionDays))
            ->count();

        $orphanUploadsCount = QuarantineUpload::query()
            ->where(function ($q): void {
                $q->whereIn('status', ['failed', 'cancelled'])
                    ->orWhere(function ($subQ): void {
                        $subQ->whereNull('asset_id')
                            ->where('created_at', '<=', now()->subHours(48));
                    });
            })
            ->count();

        return [
            'trashed_count' => $trashedCount,
            'expired_count' => $expiredCount,
            'retention_days' => $retentionDays,
            'orphan_uploads_count' => $orphanUploadsCount,
        ];
    }

    public function moveToTrash(Asset $asset, string $reason, User $user): void
    {
        if ($asset->trashed()) {
            throw new RuntimeException(__('operations.generated.t_702283d87ca2015d'));
        }

        DB::transaction(function () use ($asset, $reason, $user): void {
            $asset->deleted_by_user_id = $user->id;
            $asset->deletion_reason = $reason;
            $asset->save();

            $asset->delete();

            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id,
                'actor_user_id' => $user->id,
                'event_type' => 'asset.trashed',
                'details' => [
                    'reason' => $reason,
                    'deleted_at' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    public function restoreFromTrash(Asset $asset, User $user): void
    {
        if (! $asset->trashed()) {
            throw new RuntimeException(__('operations.generated.t_a1fb64918477bcd4'));
        }

        DB::transaction(function () use ($asset, $user): void {
            $asset->restore();
            $asset->deleted_by_user_id = null;
            $asset->deletion_reason = null;
            $asset->save();

            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id,
                'actor_user_id' => $user->id,
                'event_type' => 'asset.restored',
                'details' => [
                    'restored_at' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    public function purgeAsset(Asset $asset, string $reason, User $user): void
    {
        DB::transaction(function () use ($asset, $reason, $user): void {
            $files = AssetFile::query()->where('asset_id', $asset->id)->get();
            $deletedFilesCount = 0;

            foreach ($files as $file) {
                // Delete physical files
                $storage = Storage::disk('local');
                if ($storage->exists($file->storage_key)) {
                    $storage->delete($file->storage_key);
                    $deletedFilesCount++;
                }

                $derivatives = (array) ($file->derivatives ?? []);
                foreach ($derivatives as $key) {
                    if (is_string($key) && $storage->exists($key)) {
                        $storage->delete($key);
                    }
                }
            }

            TrashPurgeLog::create([
                'id' => (string) Str::ulid(),
                'asset_id' => $asset->id,
                'accession_number' => $asset->accession_number,
                'title' => $asset->title,
                'purged_by_user_id' => $user->id,
                'reason' => $reason,
                'deleted_files_count' => $deletedFilesCount,
            ]);

            // Clean related uploads
            QuarantineUpload::query()->where('asset_id', $asset->id)->delete();

            // Permanent delete of asset (cascades to asset_files and relations)
            $asset->forceDelete();
        });
    }

    public function purgeExpired(int $retentionDays, User $user): int
    {
        $expiredAssets = Asset::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($retentionDays))
            ->get();

        $purgedCount = 0;
        foreach ($expiredAssets as $asset) {
            $this->purgeAsset($asset, "Bewaartermijn van {$retentionDays} dagen verlopen (automatische purge).", $user);
            $purgedCount++;
        }

        return $purgedCount;
    }

    public function cleanupOrphanQuarantineUploads(int $retentionHours, User $user): int
    {
        $orphanUploads = QuarantineUpload::query()
            ->where(function ($q) use ($retentionHours): void {
                $q->whereIn('status', ['failed', 'cancelled'])
                    ->orWhere(function ($subQ) use ($retentionHours): void {
                        $subQ->whereNull('asset_id')
                            ->where('created_at', '<=', now()->subHours($retentionHours));
                    });
            })
            ->get();

        $cleanedCount = 0;

        foreach ($orphanUploads as $upload) {
            $disk = $upload->storage_disk ?: 'local';
            $storage = Storage::disk($disk);

            if ($upload->storage_key && $storage->exists($upload->storage_key)) {
                $storage->delete($upload->storage_key);
            }

            $upload->delete();
            $cleanedCount++;
        }

        return $cleanedCount;
    }
}
