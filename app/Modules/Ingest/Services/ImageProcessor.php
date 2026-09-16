<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Ingest\IngestStatus;
use App\Modules\Ingest\Models\ProcessingJob;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImageProcessor
{
    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    public function __construct(private readonly MalwareScanner $scanner) {}

    public function process(QuarantineUpload $upload): void
    {
        if (AssetFile::query()->where('storage_key', $upload->storage_key)->exists()) {
            return;
        }
        $disk = Storage::disk($upload->storage_disk);
        $stream = $disk->readStream($upload->storage_key);
        if (! is_resource($stream)) {
            throw new RuntimeException('Private upload object is unavailable.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-');
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException('Unable to reserve processing storage.');
        }
        $writtenKeys = [];
        $committed = false;
        try {
            $output = fopen($temporary, 'wb');
            if ($output === false) {
                throw new RuntimeException('Unable to open processing storage.');
            }
            try {
                $bytes = stream_copy_to_stream($stream, $output, (int) config('ingest.max_upload_bytes') + 1);
            } finally {
                fclose($output);
            }
            if ($bytes === false) {
                throw new RuntimeException('Unable to read upload.');
            }
            if ($bytes !== $upload->byte_size || $bytes > (int) config('ingest.max_upload_bytes')) {
                $this->reject('Bestandsgrootte ongeldig of upload gewijzigd.');
            }
            $scannerStatus = $this->scanner->scan($temporary);
            $sha = hash_file('sha256', $temporary);
            if ($sha === false) {
                throw new RuntimeException('Checksum calculation failed.');
            }
            if ($existingFile = AssetFile::query()->where('sha256', $sha)->first()) {
                $upload->update([
                    'duplicate_of_asset_id' => $existingFile->asset_id,
                    'duplicate_of_file_id' => $existingFile->id,
                    'detected_sha256' => $sha,
                ]);
                $this->reject('Dit bestand bestaat al in het archief. Er is geen tweede origineel toegevoegd.');
            }

            // Convert decoder warnings into explicit permanent rejection, not silent retry loops.
            set_error_handler(function (): never {
                $this->reject('De afbeelding of ingebedde metadata is beschadigd.');
            });
            try {
                $info = getimagesize($temporary);
                if ($info === false || ! isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
                    $this->reject('Het bestand is geen geldige JPEG-, PNG- of WebP-afbeelding.');
                }
                [$width, $height, $type] = $info;
                $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
                $budget = ($memoryLimit < 0 ? 536870912 : $memoryLimit) - memory_get_usage(true) - 67108864;
                if ($width * $height > (int) config('ingest.max_image_pixels') || $width * $height * 12 > $budget) {
                    $this->reject('De afbeelding overschrijdt de pixel- of veilige decodergeheugenlimiet.');
                }
                $orientation = 1;
                if ($type === IMAGETYPE_JPEG && exif_imagetype($temporary) === IMAGETYPE_JPEG) {
                    $exif = exif_read_data($temporary, 'IFD0', true);
                    $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
                    unset($exif);
                }
                $source = match ($type) {
                    IMAGETYPE_JPEG => imagecreatefromjpeg($temporary),
                    IMAGETYPE_PNG => imagecreatefrompng($temporary),
                    IMAGETYPE_WEBP => imagecreatefromwebp($temporary),
                };
                if ($source === false) {
                    $this->reject('De afbeelding kan niet worden gedecodeerd.');
                }
            } finally {
                restore_error_handler();
            }
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($source, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
            };
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                if ($rotated === false) {
                    $this->reject('Oriëntatiecorrectie mislukt.');
                }
                $source = $rotated;
            }
            $displayWidth = imagesx($source);
            $displayHeight = imagesy($source);
            $derivatives = [];
            foreach ([300, 1200, 2000] as $limit) {
                $ratio = min(1, $limit / max($displayWidth, $displayHeight));
                $targetW = max(1, (int) round($displayWidth * $ratio));
                $targetH = max(1, (int) round($displayHeight * $ratio));
                $target = imagecreatetruecolor($targetW, $targetH);
                if ($target === false) {
                    throw new RuntimeException('Derivative allocation failed.');
                }
                imagefill($target, 0, 0, 16777215);
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $displayWidth, $displayHeight);
                ob_start();
                try {
                    if (! imagejpeg($target, null, 85)) {
                        throw new RuntimeException('Derivative encoding failed.');
                    }
                    $encoded = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                unset($target);
                if ($encoded === false) {
                    throw new RuntimeException('Derivative output unavailable.');
                }
                $key = 'derivatives/'.$upload->id.'/preview-'.$limit.'.jpg';
                $writtenKeys[] = $key;
                if (! $disk->put($key, $encoded, ['visibility' => 'private', 'ContentType' => 'image/jpeg'])) {
                    throw new RuntimeException('Derivative storage failed.');
                }
                $derivatives['preview'.$limit] = $key;
            }
            unset($source);
            try {
                DB::transaction(function () use ($upload, $sha, $type, $width, $height, $orientation, $displayWidth, $displayHeight, $derivatives, $scannerStatus): void {
                    $maxVersion = (int) (AssetVersion::query()->where('asset_id', $upload->asset_id)->max('version_number') ?? 0);
                    $nextVersion = $maxVersion + 1;
                    if ($nextVersion > 1) {
                        AssetFile::query()->where('asset_id', $upload->asset_id)->update(['is_primary' => false]);
                        AssetVersion::query()->where('asset_id', $upload->asset_id)->update(['is_current' => false]);
                    }
                    $file = AssetFile::query()->create([
                        'asset_id' => $upload->asset_id, 'storage_disk' => $upload->storage_disk,
                        'storage_key' => $upload->storage_key, 'sha256' => $sha,
                        'media_type' => self::TYPES[$type], 'byte_size' => $upload->byte_size,
                        'original_filename' => $upload->original_filename, 'pixel_width' => $width, 'pixel_height' => $height,
                        'technical_metadata' => ['orientation' => $orientation, 'display_width' => $displayWidth, 'display_height' => $displayHeight, 'exif_policy' => 'Original preserved privately; GPS and EXIF not copied to previews.'],
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
                        'change_note' => $nextVersion === 1 ? 'Eerste scanopname geregistreerd.' : 'Verbeterde scanversie geüpload.',
                        'is_current' => true,
                    ]);
                    ProcessingJob::query()->create(['asset_file_id' => $file->id, 'job_type' => 'process_upload', 'status' => 'completed', 'attempts' => (int) ($upload->attempts ?? 1)]);
                });
                $committed = true;
            } catch (UniqueConstraintViolationException) {
                $this->reject('Dit bestand bestaat al in het archief. Er is geen tweede origineel toegevoegd.');
            }
        } finally {
            fclose($stream);
            unlink($temporary);
            if (! $committed && $writtenKeys !== [] && ! $disk->delete($writtenKeys)) {
                throw new RuntimeException('Derivative rollback cleanup failed.');
            }
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['files' => $message]);
    }
}
