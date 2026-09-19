<?php

declare(strict_types=1);

use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\JobOutboxMessage;
use App\Modules\Ingest\Services\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('leases and dispatches an outbox message exactly once', function (): void {
    Queue::fake();
    $message = app(TransactionalOutbox::class)->record(new ProcessUpload('upload-1'), 'quarantine-upload', 'upload-1');

    expect(app(TransactionalOutbox::class)->dispatchBatch())->toMatchArray(['dispatched' => 1, 'dead' => 0])
        ->and($message->refresh()->status)->toBe('dispatched')
        ->and($message->attempts)->toBe(1);
    app(TransactionalOutbox::class)->dispatchBatch();

    Queue::assertPushed(ProcessUpload::class, 1);
});

it('recovers expired leases and manages dead letters explicitly', function (): void {
    $outbox = app(TransactionalOutbox::class);
    $message = JobOutboxMessage::query()->create([
        'aggregate_type' => 'test',
        'aggregate_id' => '1',
        'job_class' => ProcessUpload::class,
        'queue' => 'ingest',
        'payload' => 'not-base64',
        'status' => 'leased',
        'attempts' => 7,
        'max_attempts' => 8,
        'available_at' => now()->subMinute(),
        'lease_token' => (string) str()->uuid(),
        'lease_expires_at' => now()->subSecond(),
    ]);

    expect($outbox->dispatchBatch())->toMatchArray(['retried' => 1, 'dead' => 1])
        ->and($message->refresh()->status)->toBe('dead')
        ->and($message->last_error)->toBe(RuntimeException::class)
        ->and($outbox->retryDeadLetter($message->id))->toBeTrue()
        ->and($message->refresh()->status)->toBe('pending');

    $message->update(['status' => 'dead']);
    expect($outbox->discardDeadLetter($message->id))->toBeTrue()
        ->and(JobOutboxMessage::query()->find($message->id))->toBeNull();
});

it('exposes component-oriented liveness and readiness', function (): void {
    config(['filesystems.default' => 'local']);

    $this->getJson('/api/health/live')
        ->assertOk()
        ->assertJsonPath('component', 'application');
    $this->getJson('/api/health/ready')
        ->assertOk()
        ->assertJsonPath('components.database.status', 'ok')
        ->assertJsonPath('components.storage.status', 'ok')
        ->assertJsonPath('components.queue.status', 'ok');
});
