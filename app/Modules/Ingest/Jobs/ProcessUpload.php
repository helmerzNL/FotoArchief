<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Jobs;

use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(public readonly string $uploadId) {}

    public function handle(ImageProcessor $processor): void
    {
        $token = (string) str()->uuid();
        $claimed = QuarantineUpload::query()->whereKey($this->uploadId)
            ->where(function ($query): void {
                $query->where('status', 'queued')->orWhere(function ($query): void {
                    $query->where('status', 'running')->where('started_at', '<', now()->subSeconds(180));
                });
            })->update(['status' => 'running', 'started_at' => now(), 'claim_token' => $token, 'attempts' => DB::raw('attempts + 1')]);
        if ($claimed !== 1) {
            return;
        }
        $upload = QuarantineUpload::query()->findOrFail($this->uploadId);
        try {
            $processor->process($upload);
            $this->finish($upload, $token, 'completed', null);
        } catch (ValidationException $exception) {
            $reason = implode(' ', array_merge(...array_values($exception->errors())));
            $this->finish($upload, $token, 'rejected', $reason);
        } catch (Throwable $exception) {
            Log::error('Photo processing failed.', ['upload_id' => $upload->id, 'exception_type' => $exception::class]);
            $this->finish($upload, $token, 'queued', 'Verwerking tijdelijk mislukt; automatische herpoging volgt.');
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $upload = QuarantineUpload::query()->find($this->uploadId);
        if ($upload !== null && in_array($upload->status, ['queued', 'running'], true)) {
            DB::transaction(function () use ($upload): void {
                $upload->update(['status' => 'failed', 'claim_token' => null, 'failure_reason' => 'Verwerking mislukt. Controleer worker, opslag en scanner; probeer daarna opnieuw.']);
                AssetAuditEvent::query()->create(['asset_id' => $upload->asset_id, 'event_type' => 'upload.failed', 'details' => ['upload_id' => $upload->id]]);
            });
        }
    }

    private function finish(QuarantineUpload $upload, string $token, string $status, ?string $reason): void
    {
        DB::transaction(function () use ($upload, $token, $status, $reason): void {
            if (QuarantineUpload::query()->whereKey($upload->id)->where('claim_token', $token)
                ->update(['status' => $status, 'failure_reason' => $reason, 'claim_token' => null]) === 1) {
                AssetAuditEvent::query()->create(['asset_id' => $upload->asset_id, 'event_type' => 'upload.'.$status, 'details' => ['upload_id' => $upload->id, 'attempt' => $upload->attempts, 'reason' => $reason]]);
            }
        });
    }
}
