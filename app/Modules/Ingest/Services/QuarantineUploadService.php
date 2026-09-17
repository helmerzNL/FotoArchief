<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class QuarantineUploadService
{
    public function quarantine(Asset $asset, UploadedFile $upload, ?string $userId = null): QuarantineUpload
    {
        $this->validate($upload);
        $diskName = (string) config('filesystems.default');
        $disk = Storage::disk($diskName);
        $storageKey = 'quarantine/'.str()->ulid().'/'.str()->random(32);
        $stream = fopen((string) $upload->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException(__('shared.generated.t_b5394357da3fb02d'));
        }
        try {
            if (! $disk->writeStream($storageKey, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException(__('shared.generated.t_7fe6a821c70aaab2'));
            }

            return DB::transaction(function () use ($asset, $upload, $userId, $diskName, $storageKey): QuarantineUpload {
                $record = QuarantineUpload::query()->create([
                    'asset_id' => $asset->id,
                    'uploaded_by_user_id' => $userId,
                    'storage_disk' => $diskName,
                    'storage_key' => $storageKey,
                    'byte_size' => $upload->getSize(),
                    'original_filename' => mb_substr(basename($upload->getClientOriginalName()), 0, 255),
                    'status' => 'queued',
                ]);
                // The queue row and domain row commit on the same database, never after HTTP success.
                Queue::connection('ingest')->push(new ProcessUpload($record->id));
                AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $userId, 'event_type' => 'upload.accepted', 'details' => ['upload_id' => $record->id]]);

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            if (! $disk->delete($storageKey)) {
                throw new RuntimeException(__('shared.generated.t_24f541067a7353d7'), previous: $exception);
            }
            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    public function validate(UploadedFile $upload): void
    {
        $maxBytes = (int) config('ingest.max_upload_bytes');
        $size = $upload->getSize();
        if (! $upload->isValid() || $size === false || $size <= 0 || $size > $maxBytes) {
            throw ValidationException::withMessages(['files' => "De upload moet geldig zijn en tussen 1 en {$maxBytes} bytes bevatten."]);
        }
        if (! in_array($upload->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['files' => __('shared.generated.t_f92a35ba35c44c4b')]);
        }
    }
}
