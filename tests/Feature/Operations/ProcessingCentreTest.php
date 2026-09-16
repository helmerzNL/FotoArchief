<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->artisan('migrate');
    Queue::fake();

    $this->updateAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);
    $this->manageUsersPermission = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([
        $this->updateAssetsPermission->id,
        $this->viewAssetsPermission->id,
        $this->manageUsersPermission->id,
    ]);

    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$this->viewAssetsPermission->id]);

    $this->admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->admin->roles()->attach($adminRole);

    $this->viewer = User::query()->create([
        'name' => 'Viewer User',
        'email' => 'viewer@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->viewer->roles()->attach($viewerRole);

    $this->asset = Asset::query()->create([
        'accession_number' => 'FA-PROC-1',
        'title' => 'Verwerkingstest Item',
        'created_by_user_id' => $this->admin->id,
    ]);
});

it('displays processing centre dashboard with statistics and filters for administrators', function (): void {
    QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/queued.jpg',
        'original_filename' => 'queued.jpg',
        'byte_size' => 1024,
        'status' => 'queued',
    ]);

    QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/failed.jpg',
        'original_filename' => 'failed.jpg',
        'byte_size' => 2048,
        'status' => 'failed',
        'failure_reason' => 'ClamAV scanner connection timed out.',
    ]);

    $response = $this->actingAs($this->admin)->get('/admin/operations/processing');
    $response->assertStatus(200);
    $response->assertSee('Verwerkingscentrum', false);
    $response->assertSee('queued.jpg');
    $response->assertSee('failed.jpg');
    $response->assertSee('ClamAV scanner connection timed out.');

    // Status filter
    $filterResponse = $this->actingAs($this->admin)->get('/admin/operations/processing?status=failed');
    $filterResponse->assertStatus(200);
    $filterResponse->assertSee('failed.jpg');
});

it('denies access to processing centre to unauthorized viewers', function (): void {
    $response = $this->actingAs($this->viewer)->get('/admin/operations/processing');
    $response->assertStatus(403);
});

it('allows retrying a failed processing job and pushes to ingest queue', function (): void {
    $failedUpload = QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/failed_one.jpg',
        'original_filename' => 'failed_one.jpg',
        'byte_size' => 4096,
        'status' => 'failed',
        'failure_reason' => 'Temporary network glitch.',
    ]);

    $response = $this->actingAs($this->admin)->post("/admin/operations/processing/{$failedUpload->id}/retry");
    $response->assertSessionHas('status');

    $failedUpload->refresh();
    expect($failedUpload->status)->toBe('queued');
    expect($failedUpload->failure_reason)->toBeNull();

    Queue::assertPushed(ProcessUpload::class, function ($job) use ($failedUpload): bool {
        return $job->uploadId === $failedUpload->id;
    });

    $audit = AssetAuditEvent::query()->where('asset_id', $this->asset->id)->where('event_type', 'upload.retried_from_operations')->first();
    expect($audit)->not->toBeNull();
});

it('retries all failed processing jobs in bulk', function (): void {
    $u1 = QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/f1.jpg',
        'original_filename' => 'f1.jpg',
        'byte_size' => 100,
        'status' => 'failed',
        'failure_reason' => 'Err 1',
    ]);

    $u2 = QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/f2.jpg',
        'original_filename' => 'f2.jpg',
        'byte_size' => 200,
        'status' => 'failed',
        'failure_reason' => 'Err 2',
    ]);

    $response = $this->actingAs($this->admin)->post('/admin/operations/processing/retry-all');
    $response->assertRedirect(route('admin.operations.processing.index'));

    $u1->refresh();
    $u2->refresh();
    expect($u1->status)->toBe('queued');
    expect($u2->status)->toBe('queued');

    Queue::assertPushed(ProcessUpload::class, 2);
});

it('allows cancelling a stuck job', function (): void {
    $stuckUpload = QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/stuck.jpg',
        'original_filename' => 'stuck.jpg',
        'byte_size' => 500,
        'status' => 'running',
        'started_at' => now()->subMinutes(10),
    ]);

    $response = $this->actingAs($this->admin)->post("/admin/operations/processing/{$stuckUpload->id}/cancel");
    $response->assertSessionHas('status');

    $stuckUpload->refresh();
    expect($stuckUpload->status)->toBe('failed');
    expect($stuckUpload->failure_reason)->toContain('geannuleerd');
});
