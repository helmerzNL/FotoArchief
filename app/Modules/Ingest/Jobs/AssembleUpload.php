<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Jobs;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\UploadSession;
use App\Modules\Ingest\Models\UploadSessionItem;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Ingest\Services\UploadSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AssembleUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(public readonly string $itemId) {}

    public function handle(UploadSessionService $sessions, QuarantineUploadService $uploads): void
    {
        $item = UploadSessionItem::query()->findOrFail($this->itemId);
        DB::transaction(function () use ($item, $sessions, $uploads): void {
            // Same session/item lock order as receipt writes and expiry pruning.
            $session = UploadSession::query()->whereKey($item->upload_session_id)->lockForUpdate()->firstOrFail();
            $locked = UploadSessionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'queued') {
                return;
            }
            $user = User::query()->find($session->user_id);
            if ($session->expires_at->isPast() || $session->purged_at !== null || $user === null
                || ! $user->hasPermission('assets.create') || ! $user->hasPermission('assets.view')) {
                $locked->update(['status' => 'expired', 'error_code' => 'closed']);

                return;
            }
            $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-assemble-');
            if ($temporary === false) {
                throw new RuntimeException(__('uploads.storage_error'));
            }
            try {
                $output = fopen($temporary, 'wb');
                if ($output === false) {
                    throw new RuntimeException(__('uploads.storage_error'));
                }
                try {
                    for ($position = 0; $position < (int) ceil($locked->byte_size / UploadSessionService::CHUNK_BYTES); $position++) {
                        $chunk = Storage::disk('local')->readStream($sessions->chunkPath($locked, $position));
                        if (! is_resource($chunk)) {
                            throw new RuntimeException(__('uploads.storage_error'));
                        }
                        try {
                            $expected = min(UploadSessionService::CHUNK_BYTES, $locked->byte_size - $position * UploadSessionService::CHUNK_BYTES);
                            if (stream_copy_to_stream($chunk, $output, $expected + 1) !== $expected) {
                                throw ValidationException::withMessages(['file' => __('uploads.integrity')]);
                            }
                        } finally {
                            fclose($chunk);
                        }
                    }
                } finally {
                    fclose($output);
                }
                if (filesize($temporary) !== $locked->byte_size || hash_file('sha256', $temporary) !== $locked->sha256) {
                    throw ValidationException::withMessages(['file' => __('uploads.integrity')]);
                }
                $file = new UploadedFile($temporary, $locked->filename, test: true);
                $uploads->validate($file);
                $asset = Asset::query()->create(['accession_number' => 'FA-'.str()->ulid(), 'title' => mb_substr(pathinfo($locked->filename, PATHINFO_FILENAME), 0, 255), 'created_by_user_id' => $session->user_id]);
                $upload = $uploads->quarantine($asset, $file, $session->user_id);
                $locked->update(['status' => 'received', 'quarantine_upload_id' => $upload->id, 'error_code' => null]);
            } catch (ValidationException) {
                $locked->update(['status' => 'rejected', 'error_code' => 'integrity']);
            } finally {
                if (! unlink($temporary)) {
                    Log::warning(__('uploads.storage_error'), ['item_id' => $locked->id]);
                }
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        UploadSessionItem::query()->whereKey($this->itemId)->where('status', 'queued')->update(['status' => 'failed', 'error_code' => 'assembly_failed']);
        Log::error(__('uploads.assembly_failed'), ['item_id' => $this->itemId, 'exception_type' => $exception === null ? null : $exception::class]);
    }
}
