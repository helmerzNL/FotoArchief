<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Models\User;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

class UploadRetryService
{
    public function retry(User $user, QuarantineUpload $upload): void
    {
        abort_unless($upload->asset !== null, 404);
        Gate::forUser($user)->authorize('update', $upload->asset);
        DB::transaction(function () use ($upload, $user): void {
            $locked = QuarantineUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $stale = $locked->status === 'running' && $locked->started_at?->lt(now()->subMinutes(4));
            abort_unless($locked->status === 'failed' || $stale, 409, __('catalogue.generated.t_29c9a7a2008777d8'));
            $locked->update(['status' => 'queued', 'failure_reason' => null, 'claim_token' => null]);
            Queue::connection('ingest')->push(new ProcessUpload($locked->id));
            AssetAuditEvent::query()->create(['asset_id' => $locked->asset_id, 'actor_user_id' => $user->id, 'event_type' => 'upload.retried', 'details' => ['upload_id' => $locked->id]]);
        });
    }
}
