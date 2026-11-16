<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Ingest\Exceptions\UnreadableImage;
use App\Modules\Ingest\Exceptions\UnreadablePdf;
use App\Modules\Ingest\Exceptions\UnreadableRasterImage;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Services\OriginalImageDecoder;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FileVersionService
{
    public function __construct(
        private readonly QuarantineUploadService $quarantineUploadService,
        private readonly OriginalImageDecoder $decoder,
    ) {}

    /**
     * @return Collection<int, AssetVersion>
     */
    public function getAssetVersions(Asset $asset): Collection
    {
        /** @var Collection<int, AssetVersion> $versions */
        $versions = $asset->versions()->with(['file'])->orderByDesc('version_number')->get();

        return $versions;
    }

    public function uploadNewVersion(Asset $asset, UploadedFile $file, User $actor, ?string $changeNote = null): void
    {
        $this->quarantineUploadService->validate($file);
        $upload = $this->quarantineUploadService->quarantine($asset, $file, $actor->id);

        AssetAuditEvent::query()->create([
            'asset_id' => $asset->id,
            'actor_user_id' => $actor->id,
            'event_type' => 'version.uploaded',
            'details' => [
                'original_filename' => $file->getClientOriginalName(),
                'change_note' => $changeNote ?: __('operations.generated.t_0121674ad6112d98'),
                'upload_id' => $upload->id,
            ],
        ]);
    }

    public function reprocessDerivatives(AssetFile $file, User $actor): void
    {
        $disk = Storage::disk($file->storage_disk);
        $stream = $disk->readStream($file->storage_key);
        if (! is_resource($stream)) {
            throw new RuntimeException(__('operations.generated.t_29ddb3e4b8f63093'));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-reprocess-');
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException(__('operations.generated.t_eb2afa5b6f54a65c'));
        }

        $writtenKeys = [];
        $committed = false;

        try {
            $output = fopen($temporary, 'wb');
            if ($output === false) {
                throw new RuntimeException(__('operations.generated.t_cd8d2285c54f763b'));
            }
            try {
                stream_copy_to_stream($stream, $output);
            } finally {
                fclose($output);
            }

            // Verify sha256 of immutable original
            $sha = hash_file('sha256', $temporary);
            if ($sha !== $file->sha256) {
                throw new RuntimeException(__('operations.generated.t_a0fd9a9a9b63df34'));
            }

            // The same decoding as ingest, so every accepted format (JPEG, PNG, WebP, BMP,
            // PDF, TIFF, HEIC) can be rebuilt. A damaged original is shown to the user; a
            // missing poppler or libvips binary stays a RuntimeException.
            try {
                $decoded = $this->decoder->decode($temporary);
            } catch (UnreadableImage|UnreadablePdf|UnreadableRasterImage $exception) {
                throw ValidationException::withMessages(['reprocess' => $exception->getMessage()]);
            }

            $derivatives = [];
            foreach ($this->decoder->previews($decoded->image) as $limit => $encoded) {
                $key = 'derivatives/'.$file->id.'/preview-'.$limit.'-'.time().'.jpg';
                $writtenKeys[] = $key;
                if (! $disk->put($key, $encoded, ['visibility' => 'private', 'ContentType' => 'image/jpeg'])) {
                    throw new RuntimeException(__('operations.generated.t_ecccfcb443a4ed7d'));
                }
                $derivatives['preview'.$limit] = $key;
            }
            unset($decoded);

            DB::transaction(function () use ($file, $derivatives, $actor): void {
                $file->update([
                    'derivatives' => $derivatives,
                    'processed_at' => now(),
                ]);

                // Rebuilt derivatives change what a viewer receives, so any open
                // review of this dossier is stale and must be re-read.
                $this->invalidateReview($file->asset_id);

                AssetAuditEvent::query()->create([
                    'asset_id' => $file->asset_id,
                    'actor_user_id' => $actor->id,
                    'event_type' => 'version.reprocessed',
                    'details' => [
                        'file_id' => $file->id,
                        'original_filename' => $file->original_filename,
                        'derivatives' => array_keys($derivatives),
                    ],
                ]);
            });

            $committed = true;
        } finally {
            fclose($stream);
            if (file_exists($temporary)) {
                unlink($temporary);
            }
            if (! $committed && $writtenKeys !== []) {
                $disk->delete($writtenKeys);
            }
        }
    }

    public function setActiveVersion(Asset $asset, AssetFile $file, User $actor): void
    {
        if ($file->asset_id !== $asset->id) {
            throw new RuntimeException(__('operations.generated.t_4a074aa48939b7a2'));
        }

        DB::transaction(function () use ($asset, $file, $actor): void {
            AssetFile::query()->where('asset_id', $asset->id)->update(['is_primary' => false]);
            AssetVersion::query()->where('asset_id', $asset->id)->update(['is_current' => false]);

            $file->is_primary = true;
            $file->save();

            $this->invalidateReview($asset->id);

            AssetVersion::query()
                ->where('asset_id', $asset->id)
                ->where('asset_file_id', $file->id)
                ->update(['is_current' => true]);

            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id,
                'actor_user_id' => $actor->id,
                'event_type' => 'version.activated',
                'details' => [
                    'active_file_id' => $file->id,
                    'original_filename' => $file->original_filename,
                ],
            ]);
        });
    }

    /**
     * Bumps the dossier revision so an open metadata or publication form is
     * rejected on save instead of writing a review of a file that is no longer
     * the one being served.
     */
    private function invalidateReview(string $assetId): void
    {
        Asset::query()->where('id', $assetId)->increment('lock_version');
    }
}
