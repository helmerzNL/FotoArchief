<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Ingest\Models\JobOutboxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class TransactionalOutbox
{
    public function record(
        ShouldQueue $job,
        string $aggregateType,
        string $aggregateId,
        string $queue = 'ingest',
    ): JobOutboxMessage {
        return JobOutboxMessage::query()->create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'job_class' => $job::class,
            'queue' => $queue,
            'payload' => base64_encode(serialize($job)),
            'status' => 'pending',
            'available_at' => now(),
            'max_attempts' => (int) config('outbox.max_attempts', 8),
        ]);
    }

    /**
     * @return array{dispatched: int, retried: int, dead: int}
     */
    public function dispatchBatch(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        $retried = JobOutboxMessage::query()
            ->where('status', 'leased')
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status' => 'pending',
                'lease_token' => null,
                'lease_expires_at' => null,
                'available_at' => now(),
                'last_error' => 'lease_expired',
            ]);
        $result = ['dispatched' => 0, 'retried' => $retried, 'dead' => 0];

        for ($index = 0; $index < $limit; $index++) {
            $message = $this->claimNext();
            if ($message === null) {
                break;
            }

            try {
                $job = unserialize(base64_decode($message->payload, true) ?: '', ['allowed_classes' => true]);
                if (! $job instanceof ShouldQueue || $job::class !== $message->job_class) {
                    throw new RuntimeException('Outbox payload does not match its declared queued job.');
                }
                Queue::connection((string) config('outbox.connection', 'ingest'))
                    ->push($job, '', $message->queue);
                JobOutboxMessage::query()
                    ->whereKey($message->id)
                    ->where('lease_token', $message->lease_token)
                    ->update([
                        'status' => 'dispatched',
                        'dispatched_at' => now(),
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error' => null,
                    ]);
                $result['dispatched']++;
            } catch (Throwable $exception) {
                $dead = $message->attempts >= $message->max_attempts;
                JobOutboxMessage::query()
                    ->whereKey($message->id)
                    ->where('lease_token', $message->lease_token)
                    ->update([
                        'status' => $dead ? 'dead' : 'pending',
                        'available_at' => now()->addSeconds($dead ? 0 : min(300, 2 ** min(8, $message->attempts))),
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error' => $exception::class,
                    ]);
                $result[$dead ? 'dead' : 'retried']++;
            }
        }

        return $result;
    }

    public function retryDeadLetter(string $id): bool
    {
        return JobOutboxMessage::query()->whereKey($id)->where('status', 'dead')->update([
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'last_error' => null,
        ]) === 1;
    }

    public function discardDeadLetter(string $id): bool
    {
        return JobOutboxMessage::query()->whereKey($id)->where('status', 'dead')->delete() === 1;
    }

    private function claimNext(): ?JobOutboxMessage
    {
        return DB::transaction(function (): ?JobOutboxMessage {
            $message = JobOutboxMessage::query()
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($message === null) {
                return null;
            }

            $token = (string) str()->uuid();
            $message->update([
                'status' => 'leased',
                'attempts' => $message->attempts + 1,
                'lease_token' => $token,
                'lease_expires_at' => now()->addSeconds((int) config('outbox.lease_seconds', 60)),
            ]);

            return $message->refresh();
        }, 3);
    }
}
