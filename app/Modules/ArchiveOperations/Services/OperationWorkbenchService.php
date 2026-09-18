<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\Ai\Services\AiAssetBatchService;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

final class OperationWorkbenchService
{
    public const PAUSABLE = [ProcessAiAnalysisJob::TYPE, ProcessAiIndexJob::TYPE, VerifyIntegrityJob::TYPE, StorageCopyJob::TYPE];

    public function control(OperationRun $run, User $user, string $action): void
    {
        abort_unless($user->hasPermission('users.manage'), 403);
        DB::transaction(function () use ($run, $user, $action): void {
            $run = OperationRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless(in_array($run->operation_type, self::PAUSABLE, true), 422);
            if ($action === 'pause') {
                abort_unless(in_array($run->status, [OperationRun::STATUS_QUEUED, OperationRun::STATUS_RUNNING], true), 422);
                $run->forceFill([
                    'pause_requested' => true,
                    'status' => $run->status === OperationRun::STATUS_QUEUED ? OperationRun::STATUS_PAUSED : $run->status,
                ])->save();
            } else {
                abort_unless($action === 'resume' && $run->status === OperationRun::STATUS_PAUSED, 422);
                $run->forceFill(['pause_requested' => false, 'status' => OperationRun::STATUS_QUEUED, 'claim_token' => null])->save();
                $job = OperationRunService::jobMap()[$run->operation_type];
                Queue::connection('ingest')->push(new $job($run->id));
            }
            $run->auditEvents()->create([
                'event_type' => 'operation.'.$action, 'severity' => 'info',
                'message' => __('workbench.control_saved'),
                'context' => ['actor_user_id' => $user->id],
            ]);
        });
    }

    /** @return list<string> */
    public function selectedIds(OperationRun $run): array
    {
        return array_values(array_unique(array_filter($run->payload['asset_ids'] ?? [], 'is_string')));
    }

    /** @return LengthAwarePaginator<int, array{id: string, asset: Asset|null, status: string, reason: string}> */
    public function items(OperationRun $run, int $page): LengthAwarePaginator
    {
        $ids = $this->selectedIds($run);
        $slice = array_slice($ids, ($page - 1) * 25, 25);
        $assets = Asset::query()->whereIn('id', $slice)->get()->keyBy('id');
        $events = $this->latestItemEvents($run, $slice);
        $items = [];
        foreach ($slice as $id) {
            $event = $events->get($id);
            $status = match ($event?->event_type) {
                $run->operation_type.'.item_succeeded' => 'processed',
                $run->operation_type.'.item_failed' => 'failed',
                default => $run->status === OperationRun::STATUS_CANCELLED ? 'skipped' : 'waiting',
            };
            $items[] = [
                'id' => $id, 'asset' => $assets->get($id), 'status' => $status,
                'reason' => $event->message ?? (string) ($status === 'skipped' ? __('workbench.cancelled_item') : __('workbench.not_processed')),
            ];
        }

        return new LengthAwarePaginator($items, count($ids), 25, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /** @param list<string> $ids */
    public function retrySelected(OperationRun $run, User $user, array $ids): OperationRun
    {
        abort_unless($user->hasPermission('users.manage'), 403);

        return DB::transaction(function () use ($run, $user, $ids): OperationRun {
            $run = OperationRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless($run->isFinished() && in_array($run->operation_type, [ProcessAiAnalysisJob::TYPE, ProcessAiIndexJob::TYPE], true), 422);
            $events = $this->latestItemEvents($run, $ids);
            foreach ($ids as $id) {
                if (! in_array($id, $this->selectedIds($run), true) || $events->get($id)?->event_type !== $run->operation_type.'.item_failed') {
                    throw ValidationException::withMessages(['selected' => __('workbench.only_failed')]);
                }
                if ($run->auditEvents()->where('event_type', 'operation.selected_retry')
                    ->get()->contains(fn (OperationRunAuditEvent $event): bool => array_intersect($ids, $event->context['asset_ids'] ?? []) !== [])) {
                    throw ValidationException::withMessages(['selected' => __('workbench.already_retried')]);
                }
            }
            $batch = app(AiAssetBatchService::class)->normalize($ids, $user, AiAssetBatchService::INTERNAL_FORMAT);
            $payload = array_merge(Arr::only($run->payload ?? [], ['provider', 'model']), [
                'asset_ids' => $batch['asset_ids'], 'asset_id_format' => AiAssetBatchService::INTERNAL_FORMAT,
                'cursor' => 0, 'parent_run_id' => $run->id,
            ]);
            $child = app(OperationRunService::class)->dispatchRun(
                OperationRunService::jobMap()[$run->operation_type], $run->operation_type, $user, $payload, count($ids),
            );
            $run->auditEvents()->create([
                'event_type' => 'operation.selected_retry', 'severity' => 'info',
                'message' => __('workbench.retry_saved'), 'context' => ['child_run_id' => $child->id, 'actor_user_id' => $user->id, 'asset_ids' => $ids],
            ]);

            return $child;
        });
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, OperationRunAuditEvent>
     */
    private function latestItemEvents(OperationRun $run, array $ids): Collection
    {
        return $run->auditEvents()
            ->whereIn('event_type', [$run->operation_type.'.item_succeeded', $run->operation_type.'.item_failed'])
            ->where(fn ($query) => $query->whereIn('asset_id', $ids)->orWhereIn('context->asset_id', $ids))
            ->oldest('created_at')->orderBy('id')->get()
            ->keyBy(function (OperationRunAuditEvent $event): string {
                if (is_string($event->asset_id)) {
                    return $event->asset_id;
                }
                $context = $event->getAttribute('context');
                if (! is_array($context) || ! is_string($context['asset_id'] ?? null)) {
                    throw new \UnexpectedValueException(__('recovery.errors.audit_photo_missing'));
                }

                return $context['asset_id'];
            });
    }
}
