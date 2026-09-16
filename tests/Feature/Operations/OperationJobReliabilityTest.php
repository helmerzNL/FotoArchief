<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Models\StorageRelocation;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;

uses(RefreshDatabase::class);

/**
 * A stand-in operation whose chunk behaviour each test controls, so the base
 * class contract is exercised without depending on any one real operation.
 */
class ReliabilityProbeJob extends OperationJob
{
    /** @var callable(OperationRun): array<string, mixed>|null */
    public static $handler = null;

    public static int $calls = 0;

    protected function executeChunk(OperationRun $run): array
    {
        self::$calls++;
        $handler = self::$handler;

        return $handler !== null
            ? ($handler)($run)
            : ['processed' => 1, 'failed' => 0, 'finished' => true];
    }
}

function reliabilityRun(array $attributes = []): OperationRun
{
    return OperationRun::query()->create(array_merge([
        'operation_type' => 'test.probe',
        'status' => OperationRun::STATUS_QUEUED,
    ], $attributes));
}

beforeEach(function (): void {
    ReliabilityProbeJob::$handler = null;
    ReliabilityProbeJob::$calls = 0;
});

it('keeps the stale-claim window strictly between the job timeout and the queue redelivery', function (): void {
    $retryAfter = (int) config('queue.connections.ingest.retry_after');

    // If the window equalled retry_after, a redelivery could arrive while the row
    // was not yet stale, fail to claim, and be dropped -- stranding the run.
    expect(OperationJob::STALE_CLAIM_SECONDS)->toBeGreaterThan(OperationJob::MAX_JOB_TIMEOUT_SECONDS)
        ->and(OperationJob::STALE_CLAIM_SECONDS)->toBeLessThan($retryAfter)
        ->and(OperationJob::MAX_JOB_TIMEOUT_SECONDS)->toBeLessThanOrEqual(120);
});

it('reclaims a run abandoned by a killed worker once the claim is stale', function (): void {
    $run = reliabilityRun([
        'status' => OperationRun::STATUS_RUNNING,
        'started_at' => now()->subSeconds(OperationJob::STALE_CLAIM_SECONDS + 5),
        'claim_token' => (string) Str::uuid(),
    ]);

    (new ReliabilityProbeJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and(ReliabilityProbeJob::$calls)->toBe(1);
});

it('releases a duplicate delivery back to the queue instead of deleting the only job left', function (): void {
    $run = reliabilityRun([
        'status' => OperationRun::STATUS_RUNNING,
        'started_at' => now(),
        'claim_token' => (string) Str::uuid(),
    ]);

    $released = null;
    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->andReturnUsing(function (int $delay) use (&$released): void {
        $released = $delay;
    });

    $job = new ReliabilityProbeJob($run->id);
    $job->setJob($queueJob);

    $job->handle();

    expect($released)->toBe(OperationJob::RECLAIM_DELAY_SECONDS)
        ->and(ReliabilityProbeJob::$calls)->toBe(0)
        ->and($run->fresh()->status)->toBe(OperationRun::STATUS_RUNNING);
});

it('drops a duplicate delivery once the run has actually finished', function (): void {
    $run = reliabilityRun([
        'status' => OperationRun::STATUS_COMPLETED,
        'finished_at' => now(),
    ]);

    $released = null;
    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->andReturnUsing(function (int $delay) use (&$released): void {
        $released = $delay;
    });

    $job = new ReliabilityProbeJob($run->id);
    $job->setJob($queueJob);

    $job->handle();

    expect($released)->toBeNull();
});

it('covers a fifty thousand file maintenance run within the chunk bound', function (): void {
    expect(OperationJob::MAX_CHUNKS * OperationJob::CHUNK_SIZE)->toBeGreaterThanOrEqual(50000);
});

it('fails loudly with a resume cursor instead of reporting success when the chunk bound is reached', function (): void {
    $run = reliabilityRun(['payload' => ['cursor' => '01HXCURSOR']]);
    ReliabilityProbeJob::$handler = fn (): array => ['processed' => 5, 'failed' => 0, 'finished' => false];

    (new ReliabilityProbeJob($run->id, OperationJob::MAX_CHUNKS))->handle();

    $run->refresh();

    expect($run->status)->toBe(OperationRun::STATUS_FAILED)
        ->and($run->result['truncated'])->toBeTrue()
        ->and($run->result['resume_cursor'])->toBe('01HXCURSOR')
        ->and($run->error_message)->toContain('niet afgerond');
});

it('stops a run that stops making progress rather than chaining forever', function (): void {
    $run = reliabilityRun();
    ReliabilityProbeJob::$handler = fn (): array => ['processed' => 0, 'failed' => 0, 'finished' => false];

    (new ReliabilityProbeJob($run->id))->handle();

    $run->refresh();

    expect($run->status)->toBe(OperationRun::STATUS_FAILED)
        ->and($run->error_message)->toContain('geen voortgang');
});

it('never reports a fully failed finished chunk as completed', function (): void {
    $run = reliabilityRun(['total_items' => 1]);
    ReliabilityProbeJob::$handler = fn (): array => [
        'processed' => 0,
        'failed' => 1,
        'finished' => true,
        'result' => ['provider' => 'test'],
    ];

    (new ReliabilityProbeJob($run->id))->handle();

    $run->refresh();

    expect($run->status)->toBe(OperationRun::STATUS_FAILED)
        ->and($run->processed_items)->toBe(0)
        ->and($run->failed_items)->toBe(1)
        ->and($run->result['provider'])->toBe('test')
        ->and($run->error_message)->toContain('Geen enkel item');
});

it('never copies or counts the same file twice when a copy chunk is retried', function (): void {
    Storage::fake('local');
    Storage::fake('archive');

    $asset = Asset::query()->create([
        'accession_number' => 'FA-COPY-IDEM',
        'title' => 'Kopieerdossier',
        'lock_version' => 1,
    ]);

    $bytes = str_repeat('a', 2048);
    Storage::disk('local')->put('originals/copy-idem.jpg', $bytes);

    AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/copy-idem.jpg',
        'sha256' => hash('sha256', $bytes),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($bytes),
        'original_filename' => 'copy-idem.jpg',
        'derivatives' => [],
        'ingest_status' => 'ready_private',
        'is_primary' => true,
    ]);

    $migration = StorageMigration::query()->create([
        'source_disk' => 'local',
        'target_disk' => 'archive',
        'status' => 'copying',
        'total_files' => 1,
        'copied_files' => 0,
        'verified_files' => 0,
        'failed_files' => 0,
    ]);

    $service = app(StorageMigrationService::class);

    $first = $service->relocateChunk($migration, null, 25);
    $second = $service->relocateChunk($migration, null, 25);

    expect($first['processed'])->toBe(1)
        ->and($second['processed'])->toBe(1)
        ->and(StorageRelocation::query()->where('storage_migration_id', $migration->id)->count())->toBe(1)
        ->and((int) $migration->fresh()->verified_files)->toBe(1)
        ->and((int) $migration->fresh()->copied_files)->toBe(1);
});

it('stops a copy chunk on its byte budget so large originals stay inside the job timeout', function (): void {
    expect(StorageMigrationService::CHUNK_BYTE_BUDGET)->toBeGreaterThan(0)
        ->and(StorageCopyJob::CHUNK_SIZE * 104857600)->toBeGreaterThan(StorageMigrationService::CHUNK_BYTE_BUDGET);
});
