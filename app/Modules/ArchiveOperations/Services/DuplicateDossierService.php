<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DuplicateDossierService
{
    /**
     * @return LengthAwarePaginator<int, QuarantineUpload>
     */
    public function getPendingDuplicates(int $perPage = 20): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, QuarantineUpload> $results */
        $results = QuarantineUpload::query()
            ->with(['asset', 'uploadedBy', 'duplicateOfAsset.files', 'duplicateOfFile'])
            ->whereNotNull('duplicate_of_asset_id')
            ->where('status', 'rejected')
            ->latest('id')
            ->paginate($perPage);

        return $results;
    }

    /**
     * @return array{upload: QuarantineUpload, targetAsset: Asset, targetFile: ?AssetFile}
     */
    public function getDuplicateComparison(QuarantineUpload $upload): array
    {
        $upload->load(['asset', 'uploadedBy', 'duplicateOfAsset.tags', 'duplicateOfAsset.rights', 'duplicateOfAsset.files', 'duplicateOfFile']);

        $targetAsset = $upload->duplicateOfAsset;
        if (! $targetAsset instanceof Asset) {
            throw new RuntimeException(__('operations.generated.t_17009eb555f225cf'));
        }

        return [
            'upload' => $upload,
            'targetAsset' => $targetAsset,
            'targetFile' => $upload->duplicateOfFile,
        ];
    }

    /**
     * @param  array<string, mixed>  $enrichment
     */
    public function linkAndEnrich(QuarantineUpload $upload, User $actor, array $enrichment): Asset
    {
        $comparison = $this->getDuplicateComparison($upload);
        $targetAsset = $comparison['targetAsset'];

        if ($upload->status !== 'rejected') {
            throw ValidationException::withMessages([
                'duplicate' => __('operations.generated.t_e7ac5c3bc95354b6'),
            ]);
        }

        DB::transaction(function () use ($upload, $targetAsset, $actor, $enrichment): void {
            $lockedTarget = Asset::query()->whereKey($targetAsset->id)->lockForUpdate()->firstOrFail();

            // 1. Optional metadata enrichment (description, provenance notes)
            $provenanceNote = trim((string) ($enrichment['provenance_note'] ?? ''));
            if ($provenanceNote !== '') {
                $existingDesc = (string) ($lockedTarget->description ?? '');
                $addition = __('operations.generated.t_e03aeeaa06f775b3').($upload->original_filename ?? $upload->id).']: '.$provenanceNote;
                $lockedTarget->description = trim($existingDesc.$addition);
            }

            // 2. Optional tag merging
            $newTagNames = collect(explode(',', (string) ($enrichment['tags'] ?? '')))
                ->map(fn ($tag) => trim($tag))
                ->filter(fn ($tag) => $tag !== '')
                ->unique()
                ->values();

            if ($newTagNames->isNotEmpty()) {
                $tagIds = $newTagNames->map(fn ($name) => Tag::query()->firstOrCreate(
                    ['name' => $name],
                    ['slug' => hash('sha256', $name)]
                )->id);

                $lockedTarget->tags()->syncWithoutDetaching($tagIds);
            }

            $lockedTarget->lock_version = (int) $lockedTarget->lock_version + 1;
            $lockedTarget->save();

            // 3. Log audit event on target asset
            AssetAuditEvent::query()->create([
                'asset_id' => $lockedTarget->id,
                'actor_user_id' => $actor->id,
                'event_type' => 'duplicate.linked',
                'details' => [
                    'upload_id' => $upload->id,
                    'original_filename' => $upload->original_filename,
                    'detected_sha256' => $upload->detected_sha256,
                    'provenance_note' => $provenanceNote,
                    'added_tags' => $newTagNames->all(),
                    'resolved_at' => now()->toIso8601String(),
                ],
            ]);

            // 4. Mark quarantine upload as resolved without adding any AssetFile
            $upload->update([
                'status' => 'resolved_duplicate',
                'failure_reason' => __('operations.generated.t_a3b5f636e667a7f5').$lockedTarget->accession_number.__('operations.generated.t_e03c9d9196388d1f'),
            ]);

            // 5. Clean up duplicate file from storage if present
            try {
                if ($upload->storage_disk && $upload->storage_key && Storage::disk($upload->storage_disk)->exists($upload->storage_key)) {
                    Storage::disk($upload->storage_disk)->delete($upload->storage_key);
                }
            } catch (\Throwable) {
                // Non-critical storage cleanup error
            }

            // 6. If the temporary asset created for this upload is empty, clean it up
            $uploadAsset = $upload->asset;
            if ($uploadAsset !== null && $uploadAsset->id !== $lockedTarget->id && $uploadAsset->files()->count() === 0) {
                $uploadAsset->delete();
            }
        });

        return $targetAsset;
    }
}
