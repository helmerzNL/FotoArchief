<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Models\User;
use App\Modules\Ingest\Jobs\AssembleUpload;
use App\Modules\Ingest\Models\UploadSession;
use App\Modules\Ingest\Models\UploadSessionItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UploadSessionService
{
    public const CHUNK_BYTES = 4_194_304;

    public const BATCH_BYTES = 1_073_741_824;

    /** @param list<array{filename: string, byte_size: int, sha256: string}> $files */
    public function create(User $user, string $key, array $files): UploadSession
    {
        abort_unless($user->hasPermission('assets.create') && $user->hasPermission('assets.view'), 403);
        $manifest = hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $key, $files, $manifest): UploadSession {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = UploadSession::query()->where('user_id', $user->id)->where('client_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->manifest_sha256, $manifest), 409, __('uploads.conflict'));

                return $existing;
            }
            // Expired bytes still count until pruning confirms their removal.
            if (UploadSession::query()->where('user_id', $user->id)->whereNull('purged_at')->count() >= 4
                || array_sum(array_column($files, 'byte_size')) > self::BATCH_BYTES) {
                throw ValidationException::withMessages(['files' => __('uploads.quota')]);
            }
            $session = UploadSession::query()->create(['user_id' => $user->id, 'client_key' => $key, 'manifest_sha256' => $manifest, 'expires_at' => now()->addDays(7)]);
            foreach ($files as $file) {
                $session->items()->create($file);
            }

            return $session;
        });
    }

    public function authorize(User $user, UploadSession $session): void
    {
        abort_unless($session->user_id === $user->id && $user->hasPermission('assets.view') && $user->hasPermission('assets.create'), 403);
    }

    public function chunk(User $user, UploadSession $session, UploadSessionItem $item, int $position, UploadedFile $chunk): void
    {
        $this->authorize($user, $session);
        abort_unless($item->upload_session_id === $session->id, 404);
        DB::transaction(function () use ($session, $item, $position, $chunk): void {
            $lockedSession = UploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($lockedSession);
            $locked = UploadSessionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $expected = min(self::CHUNK_BYTES, $locked->byte_size - $position * self::CHUNK_BYTES);
            abort_unless($position >= 0 && $expected > 0 && $chunk->isValid() && $chunk->getSize() === $expected, 422, __('uploads.chunk_size'));
            $sha = hash_file('sha256', $chunk->getPathname());
            $previous = DB::table('upload_session_chunks')->where('upload_session_item_id', $item->id)->where('position', $position)->first();
            if ($previous !== null) {
                abort_unless($previous->sha256 === $sha, 409, __('uploads.conflict'));

                return;
            }
            abort_unless($locked->status === 'receiving', 409, __('uploads.conflict'));
            $path = $this->chunkPath($locked, $position);
            if (! Storage::disk('local')->putFileAs(dirname($path), $chunk, basename($path), ['visibility' => 'private'])) {
                throw new RuntimeException(__('uploads.storage_error'));
            }
            DB::table('upload_session_chunks')->insert(['upload_session_item_id' => $item->id, 'position' => $position, 'byte_size' => $expected, 'sha256' => $sha]);
        });
    }

    public function finalize(User $user, UploadSession $session, UploadSessionItem $item): void
    {
        $this->authorize($user, $session);
        abort_unless($item->upload_session_id === $session->id, 404);
        DB::transaction(function () use ($session, $item): void {
            $lockedSession = UploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($lockedSession);
            $locked = UploadSessionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['queued', 'received'], true)) {
                return;
            }
            abort_unless(in_array($locked->status, ['receiving', 'failed'], true), 409, __('uploads.conflict'));
            abort_unless(DB::table('upload_session_chunks')->where('upload_session_item_id', $item->id)->count() === (int) ceil($locked->byte_size / self::CHUNK_BYTES), 422, __('uploads.incomplete'));
            $locked->update(['status' => 'queued', 'error_code' => null]);
            Queue::connection('ingest')->push(new AssembleUpload($item->id));
        });
    }

    public function close(User $user, UploadSession $session): void
    {
        $this->authorize($user, $session);
        DB::transaction(function () use ($session): void {
            $locked = UploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->items()->whereIn('status', ['receiving', 'queued', 'failed'])->exists(), 409, __('uploads.incomplete'));
            if ($locked->closed_at === null) {
                $locked->update(['closed_at' => now()]);
            }
        });
    }

    public function chunkPath(UploadSessionItem $item, int $position): string
    {
        return 'upload-sessions/'.$item->upload_session_id.'/'.$item->id.'/'.$position;
    }

    public function assertOpen(UploadSession $session): void
    {
        abort_if($session->closed_at !== null || $session->expires_at->isPast() || $session->purged_at !== null, 409, __('uploads.closed'));
    }

    public function prune(): int
    {
        $count = 0;
        foreach (UploadSession::query()->whereNull('purged_at')->where(fn ($query) => $query->whereNotNull('closed_at')->orWhere('expires_at', '<', now()))->limit(100)->get() as $session) {
            DB::transaction(function () use ($session, &$count): void {
                $locked = UploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($locked->purged_at !== null) {
                    return;
                }
                if (! Storage::disk('local')->deleteDirectory('upload-sessions/'.$locked->id)) {
                    throw new RuntimeException(__('uploads.storage_error'));
                }
                $locked->update(['purged_at' => now()]);
                $locked->items()->whereIn('status', ['receiving', 'queued', 'failed'])->update(['status' => 'expired', 'error_code' => 'closed']);
                $count++;
            });
        }

        return $count;
    }
}
