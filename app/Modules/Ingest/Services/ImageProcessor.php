<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Ingest\Exceptions\UnreadableImage;
use App\Modules\Ingest\Exceptions\UnreadablePdf;
use App\Modules\Ingest\Exceptions\UnreadableRasterImage;
use App\Modules\Ingest\IngestStatus;
use App\Modules\Ingest\Models\ProcessingJob;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImageProcessor
{
    private readonly OriginalImageDecoder $decoder;

    /**
     * $pdfs and $rasters configure the default decoder (tests point them at missing
     * binaries); pass $decoder to replace it entirely.
     */
    public function __construct(private readonly MalwareScanner $scanner, ?PdfPageRenderer $pdfs = null, ?RasterImageConverter $rasters = null, ?OriginalImageDecoder $decoder = null)
    {
        $this->decoder = $decoder ?? new OriginalImageDecoder($pdfs, $rasters);
    }

    public function process(QuarantineUpload $upload): void
    {
        $existing = AssetFile::query()->where('storage_key', $upload->storage_key)->first(['id', 'asset_id']);
        if ($existing !== null) {
            // A retry of an upload that was already stored. Logged so that an upload
            // that ends "completed" without a file of its own can be explained.
            Log::warning(__('shared.ingest_log.already_stored'), [
                'upload_id' => $upload->id, 'asset_id' => $upload->asset_id,
                'existing_file_id' => $existing->id, 'existing_asset_id' => $existing->asset_id,
            ]);

            return;
        }
        $disk = Storage::disk($upload->storage_disk);
        $stream = $disk->readStream($upload->storage_key);
        if (! is_resource($stream)) {
            throw new RuntimeException(__('shared.generated.t_98f40b44e233206c'));
        }
        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-');
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException(__('shared.generated.t_64ba5f5d17c312f3'));
        }
        $writtenKeys = [];
        $committed = false;
        try {
            $output = fopen($temporary, 'wb');
            if ($output === false) {
                throw new RuntimeException(__('shared.generated.t_13b0a96411370683'));
            }
            try {
                $bytes = stream_copy_to_stream($stream, $output, (int) config('ingest.max_upload_bytes') + 1);
            } finally {
                fclose($output);
            }
            if ($bytes === false) {
                throw new RuntimeException(__('shared.generated.t_fe45ff80266ec104'));
            }
            if ($bytes !== $upload->byte_size || $bytes > (int) config('ingest.max_upload_bytes')) {
                $this->reject(__('shared.generated.t_7ecf77102d4c1003'));
            }
            $scannerStatus = $this->scanner->scan($temporary);
            $sha = hash_file('sha256', $temporary);
            if ($sha === false) {
                throw new RuntimeException(__('shared.generated.t_89b04da6638a8265'));
            }
            if ($existingFile = AssetFile::query()->where('sha256', $sha)->first()) {
                $upload->update([
                    'duplicate_of_asset_id' => $existingFile->asset_id,
                    'duplicate_of_file_id' => $existingFile->id,
                    'detected_sha256' => $sha,
                ]);
                $this->reject(__('shared.generated.t_bdbb01530c9e801f'));
            }

            try {
                $decoded = $this->decoder->decode($temporary);
            } catch (UnreadableImage|UnreadablePdf|UnreadableRasterImage $exception) {
                $this->reject($exception->getMessage());
            }
            $derivatives = [];
            foreach ($this->decoder->previews($decoded->image) as $limit => $encoded) {
                $key = 'derivatives/'.$upload->id.'/preview-'.$limit.'.jpg';
                $writtenKeys[] = $key;
                if (! $disk->put($key, $encoded, ['visibility' => 'private', 'ContentType' => 'image/jpeg'])) {
                    throw new RuntimeException(__('shared.generated.t_a159ac9c1c8ac40e'));
                }
                $derivatives['preview'.$limit] = $key;
            }
            $mediaType = $decoded->mediaType ?? OriginalImageDecoder::TYPES[$decoded->type];
            $width = $decoded->width;
            $height = $decoded->height;
            $technicalMetadata = ($decoded->pdfPages !== null ? ['page_count' => $decoded->pdfPages, 'preview_page' => 1] : [])
                + ['orientation' => $decoded->orientation, 'display_width' => $decoded->displayWidth, 'display_height' => $decoded->displayHeight, 'exif_policy' => __('shared.generated.t_b6a2b3d49e20c8e8')];
            unset($decoded);
            try {
                DB::transaction(function () use ($upload, $sha, $mediaType, $width, $height, $technicalMetadata, $derivatives, $scannerStatus): void {
                    $maxVersion = (int) (AssetVersion::query()->where('asset_id', $upload->asset_id)->max('version_number') ?? 0);
                    $nextVersion = $maxVersion + 1;
                    if ($nextVersion > 1) {
                        AssetFile::query()->where('asset_id', $upload->asset_id)->update(['is_primary' => false]);
                        AssetVersion::query()->where('asset_id', $upload->asset_id)->update(['is_current' => false]);
                        // A replaced primary changes what viewers receive, so an open
                        // review of the previous scan must not be saved over it.
                        Asset::query()->where('id', $upload->asset_id)->increment('lock_version');
                    }
                    $file = AssetFile::query()->create([
                        'asset_id' => $upload->asset_id, 'storage_disk' => $upload->storage_disk,
                        'storage_key' => $upload->storage_key, 'sha256' => $sha,
                        'media_type' => $mediaType, 'byte_size' => $upload->byte_size,
                        'original_filename' => $upload->original_filename, 'original_path' => $upload->original_path, 'pixel_width' => $width, 'pixel_height' => $height,
                        'technical_metadata' => $technicalMetadata,
                        'derivatives' => $derivatives, 'validated_at' => now(), 'processed_at' => now(),
                        'scanned_at' => $scannerStatus === 'clean' ? now() : null,
                        'ingest_status' => IngestStatus::ReadyPrivate->value, 'scanner_status' => $scannerStatus,
                        'is_primary' => true,
                    ]);
                    AssetVersion::query()->create([
                        'asset_id' => $upload->asset_id,
                        'asset_file_id' => $file->id,
                        'version_number' => $nextVersion,
                        'change_type' => $nextVersion === 1 ? 'initial_scan' : 'rescan',
                        'change_note' => $nextVersion === 1 ? __('shared.generated.t_15980262eafd9df4') : __('shared.generated.t_b223a7b07a04bbd5'),
                        'is_current' => true,
                    ]);
                    ProcessingJob::query()->create(['asset_file_id' => $file->id, 'job_type' => 'process_upload', 'status' => 'completed', 'attempts' => (int) ($upload->attempts ?? 1)]);
                });
                $committed = true;
            } catch (UniqueConstraintViolationException) {
                $this->reject(__('shared.generated.t_bdbb01530c9e801f'));
            }
        } finally {
            fclose($stream);
            unlink($temporary);
            if (! $committed && $writtenKeys !== [] && ! $disk->delete($writtenKeys)) {
                throw new RuntimeException(__('shared.generated.t_710e4e795d6e27cd'));
            }
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['files' => $message]);
    }
}
