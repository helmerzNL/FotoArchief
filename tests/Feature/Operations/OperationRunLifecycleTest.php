<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Jobs\RebuildDerivativesJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OperationRunDriver;

beforeEach(function (): void {
    $this->artisan('migrate');

    $manage = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);
    $update = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);
    $view = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([$manage->id, $update->id, $view->id]);

    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$view->id]);

    $this->admin = User::query()->create([
        'name' => 'Run Admin',
        'email' => 'runadmin@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->admin->roles()->attach($adminRole);

    $this->viewer = User::query()->create([
        'name' => 'Run Viewer',
        'email' => 'runviewer@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->viewer->roles()->attach($viewerRole);

    Storage::fake('local');
});

function operationRunsCreateFile(string $accession, string $key, string $bytes, User $admin): AssetFile
{
    $asset = Asset::query()->create([
        'title' => "Asset {$accession}",
        'accession_number' => $accession,
        'created_by_user_id' => $admin->id,
    ]);

    Storage::disk('local')->put($key, $bytes);

    $derivatives = [];
    foreach ([300, 1200, 2000] as $size) {
        $derivativeKey = 'derivatives/'.pathinfo($key, PATHINFO_FILENAME).'-'.$size.'.jpg';
        Storage::disk('local')->put($derivativeKey, 'preview-'.$size);
        $derivatives['preview'.$size] = $derivativeKey;
    }

    return AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => $key,
        'sha256' => hash('sha256', $bytes),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($bytes),
        'original_filename' => basename($key),
        'derivatives' => $derivatives,
        'ingest_status' => 'ready_private',
        'is_primary' => true,
    ]);
}

it('queues integrity verification instead of hashing files inside the request', function (): void {
    operationRunsCreateFile('FA-RUN-001', 'originals/run-001.jpg', 'archival-bytes-001', $this->admin);

    $response = $this->actingAs($this->admin)->post('/admin/operations/integrity/run');
    $response->assertRedirect('/admin/operations/integrity');

    $run = OperationRun::query()->where('operation_type', VerifyIntegrityJob::TYPE)->latest('created_at')->firstOrFail();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED);
    expect($run->processed_items)->toBe(0);
    expect($run->total_items)->toBe(1);

    $run = OperationRunDriver::drive($run);

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);
    expect($run->processed_items)->toBe(1);
    expect($run->failed_items)->toBe(0);
    expect($run->finished_at)->not->toBeNull();
});

it('keeps every job bounded and continues a long run across chunks', function (): void {
    $total = OperationJob::CHUNK_SIZE + 3;

    for ($i = 1; $i <= $total; $i++) {
        $suffix = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        operationRunsCreateFile("FA-CHUNK-{$suffix}", "originals/chunk-{$suffix}.jpg", "bytes-{$suffix}", $this->admin);
    }

    $run = app(OperationRunService::class)->dispatchRun(
        VerifyIntegrityJob::class,
        VerifyIntegrityJob::TYPE,
        $this->admin,
        [],
        $total,
    );

    // A single job must stop at the chunk boundary and leave the run re-queued.
    (new VerifyIntegrityJob($run->id))->handle();
    $run->refresh();

    expect($run->status)->toBe(OperationRun::STATUS_QUEUED);
    expect($run->processed_items)->toBe(OperationJob::CHUNK_SIZE);

    $run = OperationRunDriver::drive($run);

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);
    expect($run->processed_items)->toBe($total);
});

it('records a failure with an actionable message and resumes from the cursor on retry', function (): void {
    $file = operationRunsCreateFile('FA-FAIL-001', 'originals/fail-001.jpg', 'archival-bytes-fail', $this->admin);

    $run = app(OperationRunService::class)->dispatchRun(
        VerifyIntegrityJob::class,
        VerifyIntegrityJob::TYPE,
        $this->admin,
        [],
        1,
    );

    $job = new VerifyIntegrityJob($run->id);
    $job->failed(new RuntimeException('Opslagschijf [local] is niet bereikbaar.'));

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_FAILED);
    expect($run->error_message)->toContain('Opslagschijf [local]');
    expect($run->finished_at)->not->toBeNull();

    // A viewer may never restart destructive or expensive work.
    $this->actingAs($this->viewer)
        ->post("/admin/operations/runs/{$run->id}/retry")
        ->assertForbidden();

    $response = $this->actingAs($this->admin)->post("/admin/operations/runs/{$run->id}/retry");
    $response->assertRedirect('/admin/operations/runs');

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED);
    expect($run->error_message)->toBeNull();

    $run = OperationRunDriver::drive($run);
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);
    expect($run->processed_items)->toBe(1);
    expect($file->fresh())->not->toBeNull();
});

it('refuses to retry a run that has not failed', function (): void {
    $run = app(OperationRunService::class)->dispatchRun(
        VerifyIntegrityJob::class,
        VerifyIntegrityJob::TYPE,
        $this->admin,
    );

    $this->actingAs($this->admin)
        ->post("/admin/operations/runs/{$run->id}/retry")
        ->assertStatus(422);
});

it('claims a run exactly once so two workers cannot double-process it', function (): void {
    operationRunsCreateFile('FA-CLAIM-001', 'originals/claim-001.jpg', 'archival-bytes-claim', $this->admin);

    $run = app(OperationRunService::class)->dispatchRun(
        VerifyIntegrityJob::class,
        VerifyIntegrityJob::TYPE,
        $this->admin,
        [],
        1,
    );

    (new VerifyIntegrityJob($run->id))->handle();
    // A duplicate delivery of the same chunk must be a no-op, not a second pass.
    (new VerifyIntegrityJob($run->id))->handle();

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);
    expect($run->processed_items)->toBe(1);
});

it('queues a single derivative rebuild and keeps the job timeout inside the worker limit', function (): void {
    $bytes = (function (): string {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image, null, 85);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    })();

    $file = operationRunsCreateFile('FA-REBUILD-001', 'originals/rebuild-001.jpg', $bytes, $this->admin);

    // Remove a derivative so the job has real work: it must be rebuilt from the
    // untouched original, as JPEG, exactly like ingest produces it.
    Storage::disk('local')->delete($file->derivatives['preview300']);
    expect(Storage::disk('local')->exists($file->derivatives['preview300']))->toBeFalse();

    $response = $this->actingAs($this->admin)->post("/admin/operations/integrity/{$file->id}/rebuild");
    $response->assertRedirect();

    $run = OperationRun::query()->where('operation_type', RebuildDerivativesJob::TYPE)->latest('created_at')->firstOrFail();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED);

    $run = OperationRunDriver::drive($run);
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);

    $file->refresh();
    expect($file->derivatives)->toHaveKey('preview300');
    expect(Storage::disk('local')->exists($file->derivatives['preview300']))->toBeTrue();
    expect(substr(Storage::disk('local')->get($file->derivatives['preview300']), 0, 3))->toBe("\xFF\xD8\xFF");
    expect(hash('sha256', Storage::disk('local')->get($file->storage_key)))->toBe($file->sha256);

    // A per-job timeout overrides the worker timeout, so it must stay under it and
    // strictly below the ingest connection retry_after.
    expect(OperationJob::MAX_JOB_TIMEOUT_SECONDS)->toBeLessThanOrEqual(120);
    expect(OperationJob::MAX_JOB_TIMEOUT_SECONDS)->toBeLessThan((int) config('queue.connections.ingest.retry_after'));
});

it('shows the run overview to operators and hides it from viewers', function (): void {
    $run = app(OperationRunService::class)->dispatchRun(
        VerifyIntegrityJob::class,
        VerifyIntegrityJob::TYPE,
        $this->admin,
    );
    OperationRunAuditEvent::query()->create([
        'operation_run_id' => $run->id,
        'event_type' => 'ai.analysis.item_failed',
        'severity' => 'error',
        'message' => 'Veilige testfout voor het auditlog.',
        'context' => ['provider' => 'openai', 'attempt' => 1],
    ]);

    $this->actingAs($this->viewer)->get('/admin/operations/runs')->assertForbidden();

    $response = $this->actingAs($this->admin)->get('/admin/operations/runs');
    $response->assertOk();
    $response->assertSee('Achtergrondtaken');
    $response->assertSee(VerifyIntegrityJob::TYPE);
    $response->assertSee('Auditlog (1)');
    $response->assertSee('Veilige testfout voor het auditlog.');
    $response->assertSee('Technische context');
});
