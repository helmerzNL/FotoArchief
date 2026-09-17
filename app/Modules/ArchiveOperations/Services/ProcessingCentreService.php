<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class ProcessingCentreService
{
    /**
     * @return array{total: int, queued: int, running: int, failed: int, completed: int, rejected: int, stale: int}
     */
    public function getStatistics(): array
    {
        $staleThreshold = now()->subMinutes(4)->toDateTimeString();

        $raw = DB::table('quarantine_uploads')
            ->selectRaw("
                count(*) as total,
                count(case when status = 'queued' then 1 end) as queued,
                count(case when status = 'running' then 1 end) as running,
                count(case when status = 'failed' then 1 end) as failed,
                count(case when status = 'completed' then 1 end) as completed,
                count(case when status = 'rejected' then 1 end) as rejected,
                count(case when status = 'running' and started_at < ? then 1 end) as stale
            ", [$staleThreshold])
            ->first();

        /** @var array<string, mixed> $counts */
        $counts = (array) $raw;

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'queued' => (int) ($counts['queued'] ?? 0),
            'running' => (int) ($counts['running'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
            'stale' => (int) ($counts['stale'] ?? 0),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, QuarantineUpload>
     */
    public function getUploads(?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = QuarantineUpload::query()->with(['asset', 'uploadedBy'])->latest('id');

        if ($status && $status !== 'all') {
            if ($status === 'stale') {
                $query->where('status', 'running')->where('started_at', '<', now()->subMinutes(4));
            } else {
                $query->where('status', $status);
            }
        }

        /** @var LengthAwarePaginator<int, QuarantineUpload> $results */
        $results = $query->paginate($perPage);

        return $results;
    }

    public function retryUpload(QuarantineUpload $upload, User $actor): void
    {
        DB::transaction(function () use ($upload, $actor): void {
            $locked = QuarantineUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $stale = $locked->status === 'running' && $locked->started_at?->lt(now()->subMinutes(4));

            if (! in_array($locked->status, ['failed', 'rejected'], true) && ! $stale) {
                throw new RuntimeException(__('operations.generated.t_690ea86211184d99'));
            }

            $locked->update([
                'status' => 'queued',
                'failure_reason' => null,
                'claim_token' => null,
            ]);

            Queue::connection('ingest')->push(new ProcessUpload($locked->id));

            AssetAuditEvent::query()->create([
                'asset_id' => $locked->asset_id,
                'actor_user_id' => $actor->id,
                'event_type' => 'upload.retried_from_operations',
                'details' => ['upload_id' => $locked->id],
            ]);
        });
    }

    public function retryAllFailed(User $actor): int
    {
        $failedUploads = QuarantineUpload::query()->where('status', 'failed')->get();
        $retriedCount = 0;

        foreach ($failedUploads as $upload) {
            $this->retryUpload($upload, $actor);
            $retriedCount++;
        }

        return $retriedCount;
    }

    public function cancelUpload(QuarantineUpload $upload, User $actor): void
    {
        DB::transaction(function () use ($upload, $actor): void {
            $locked = QuarantineUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'status' => 'failed',
                'failure_reason' => __('operations.generated.t_3753e6b8a8f4e781'),
                'claim_token' => null,
            ]);

            AssetAuditEvent::query()->create([
                'asset_id' => $locked->asset_id,
                'actor_user_id' => $actor->id,
                'event_type' => 'upload.cancelled_from_operations',
                'details' => ['upload_id' => $locked->id],
            ]);
        });
    }
}
