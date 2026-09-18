<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\BackupRegisterService;
use App\Modules\ArchiveOperations\Services\OperationalIncidentService;
use App\Modules\ArchiveOperations\Services\RecoveryReadinessService;
use App\Modules\ArchiveOperations\Services\RestoreDrillService;
use App\Modules\ArchiveOperations\Services\SystemDiagnosticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->create(['name' => 'Recovery', 'email' => 'recovery@example.test', 'password' => 'unused']);
    $this->admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $this->viewer = User::query()->create(['name' => 'Viewer', 'email' => 'recovery-view@example.test', 'password' => 'unused']);
    $this->viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
    Http::preventStrayRequests();
});

it('persists guided diagnostic reports only after an administrator confirms', function (): void {
    $this->mock(SystemDiagnosticsService::class)->shouldReceive('getAllDiagnostics')->once()->andReturn([
        'php' => ['status' => 'ok'], 'scanner' => ['status' => 'critical', 'remediation' => 'Scanner not reachable'],
    ]);
    $this->actingAs($this->viewer)->get('/admin/operations/recovery')->assertForbidden();
    $this->post('/admin/operations/recovery/check', ['kind' => 'installation', 'confirm' => 1])->assertForbidden();
    $this->actingAs($this->admin)->post('/admin/operations/recovery/check', ['kind' => 'installation'])->assertSessionHasErrors('confirm');
    $this->post('/admin/operations/recovery/check', ['kind' => 'installation', 'confirm' => 1])->assertRedirect();
    $row = DB::table('recovery_checks')->sole();
    $report = json_decode($row->report, true);
    expect($report['ready'])->toBeFalse()->and($row->actor_user_id)->toBe($this->admin->id);
    $this->get('/admin/operations/recovery')->assertOk()->assertSee('Scanner not reachable')->assertDontSee('unused');
});

it('blocks upgrades for active or paused work missing backup proof space and version', function (): void {
    $diagnostics = array_fill_keys(['php', 'extensions', 'storage', 'database', 'limits', 'scanner', 'worker', 'activity'], ['status' => 'ok']);
    $this->mock(SystemDiagnosticsService::class)->shouldReceive('getAllDiagnostics')->andReturn($diagnostics);
    OperationRun::query()->create(['operation_type' => 'ai.index', 'status' => 'paused']);
    $report = app(RecoveryReadinessService::class)->check('upgrade', '0.0.1', 1000000000000000);
    $checks = collect($report['checks'])->keyBy('key');
    expect($report['ready'])->toBeFalse()
        ->and($checks['active_tasks']['status'])->toBe('blocked')
        ->and($checks['backup_evidence']['status'])->toBe('blocked')
        ->and($checks['free_space']['status'])->toBe('blocked')
        ->and($checks['target_version']['status'])->toBe('blocked')
        ->and($checks['migrations']['status'])->toBe('ok')
        ->and($report['target_release_migrations_inspected'])->toBeFalse();
    DB::table('migrations')->where('migration', '2026_10_05_000000_create_ai_relevance_labels')->delete();
    $pending = app(RecoveryReadinessService::class)->check('upgrade', '99.0.0', 1);
    expect(collect($pending['checks'])->keyBy('key')['migrations']['status'])->toBe('blocked');
});

it('verifies backup manifests idempotently without inventing restore evidence', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-register-test-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory, 0700);
    try {
        File::put($directory.'/database.dump', 'synthetic-not-restorable');
        File::put($directory.'/storage-app.tar', 'synthetic-not-restorable');
        File::put($directory.'/VERSION', trim(File::get(base_path('VERSION')))."\n");
        File::put($directory.'/FORMAT', "fotoarchief-local-backup-v1\n");
        $manifest = '';
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT'] as $file) {
            $manifest .= hash_file('sha256', $directory.'/'.$file).'  '.$file."\n";
        }
        File::put($directory.'/SHA256SUMS', $manifest);
        $service = app(BackupRegisterService::class);
        $backup = $service->register($directory);
        expect($service->register($directory)->id)->toBe($backup->id)
            ->and($backup->drills()->count())->toBe(0);
        $this->actingAs($this->admin)->get('/admin/operations/recovery')->assertOk()->assertSee('Nog niet bewezen');
        expect(fn () => app(RestoreDrillService::class)->run($backup, 'production', $directory.'/bad', false))->toThrow(RuntimeException::class, __('recovery.errors.confirm_target'));
        File::put($directory.'/database.dump', 'tampered');
        expect(fn () => $service->register($directory))->toThrow(RuntimeException::class, 'manifest');
        expect(BackupRecord::query()->count())->toBe(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('requires recent backup and restore evidence even when all diagnostics pass', function (): void {
    $this->freezeTime();
    $diagnostics = array_fill_keys(['php', 'extensions', 'storage', 'database', 'limits', 'scanner', 'worker', 'activity'], ['status' => 'ok']);
    $this->mock(SystemDiagnosticsService::class)->shouldReceive('getAllDiagnostics')->andReturn($diagnostics);
    $backup = BackupRecord::query()->create([
        'version' => trim(File::get(base_path('VERSION'))), 'location' => '/unit-test-evidence-only',
        'manifest_sha256' => str_repeat('a', 64), 'byte_size' => 1, 'checksum_verified_at' => now()->subHours(23),
    ]);
    $drill = $backup->drills()->create([
        'target_database' => 'unit_restore_drill', 'target_directory' => '/unit-test-target',
        'status' => 'verified', 'finished_at' => now()->subDays(29),
    ]);
    $service = app(RecoveryReadinessService::class);
    expect($service->check('upgrade', '99.0.0', 1)['ready'])->toBeTrue();
    $backup->update(['checksum_verified_at' => now()->subHours(25)]);
    expect($service->check('upgrade', '99.0.0', 1)['ready'])->toBeFalse();
    $backup->update(['checksum_verified_at' => now()]);
    $drill->update(['finished_at' => now()->subDays(31)]);
    expect($service->check('upgrade', '99.0.0', 1)['ready'])->toBeFalse();
    $drill->update(['status' => 'failed', 'finished_at' => now()]);
    expect($service->check('upgrade', '99.0.0', 1)['ready'])->toBeFalse();
});

it('deduplicates acknowledgement escalates and sends recovery without leaking transport secrets', function (): void {
    config(['operations.alerts.enabled' => true, 'operations.alerts.webhook_url' => 'https://ops.example.test/hook']);
    Http::fake(['https://ops.example.test/hook' => Http::response([], 202)]);
    $service = app(OperationalIncidentService::class);
    $payload = ['incidents' => [['key' => 'scanner', 'severity' => 'warning', 'title' => 'Scanner', 'detail' => 'Not ready']]];
    $first = $service->evaluate($payload, false);
    expect($first['sent'])->toBeTrue()->and($service->evaluate($payload, false)['reason'])->toBe('deduplicated');
    $id = DB::table('operational_incidents')->value('id');
    $this->actingAs($this->viewer)->post('/admin/operations/recovery/incidents/'.$id.'/acknowledge', ['confirm' => 1])->assertForbidden();
    $this->actingAs($this->admin)->post('/admin/operations/recovery/incidents/'.$id.'/acknowledge', ['confirm' => 1])->assertRedirect();
    expect(DB::table('operational_incidents')->value('acknowledged_by_user_id'))->toBe($this->admin->id);
    $payload['incidents'][0]['severity'] = 'critical';
    $second = $service->evaluate($payload, false);
    expect($second['sent'])->toBeTrue()->and($second['payload']['incidents'][0]['event_id'])->not->toBe($first['payload']['incidents'][0]['event_id'])
        ->and(DB::table('operational_incidents')->value('acknowledged_at'))->toBeNull();
    $recovery = $service->evaluate(['incidents' => []], false);
    expect($recovery['sent'])->toBeTrue()->and($recovery['payload']['incidents'][0]['state'])->toBe('resolved');
    expect($service->evaluate(['incidents' => []], false)['sent'])->toBeFalse();
    $this->post('/admin/operations/recovery/incidents/'.$id.'/acknowledge', ['confirm' => 1])->assertSessionHasErrors('incident');
    expect($service->evaluate($payload, false)['sent'])->toBeTrue();
    Http::assertSentCount(4);
});

it('keeps pending delivery identifiers stable through failures and leaves dry runs read only', function (): void {
    config(['operations.alerts.enabled' => true, 'operations.alerts.webhook_url' => 'https://ops.example.test/hook']);
    Http::fake(['https://ops.example.test/hook' => Http::sequence()->push('private response must not be stored', 500)->push([], 202)]);
    $payload = ['incidents' => [['key' => 'worker', 'severity' => 'critical', 'title' => 'Worker', 'detail' => 'Offline']]];
    $service = app(OperationalIncidentService::class);
    $service->evaluate($payload, true);
    expect(DB::table('operational_incidents')->count())->toBe(0);
    expect(fn () => $service->evaluate($payload, false))->toThrow(RuntimeException::class);
    $id = DB::table('operational_incidents')->value('notification_id');
    expect(DB::table('operational_incidents')->value('delivery_error'))->not->toContain('private response');
    $result = $service->evaluate($payload, false);
    expect($result['payload']['incidents'][0]['event_id'])->toBe($id)->and($result['sent'])->toBeTrue();
});

it('persists a redacted connection error and retries with the original event identifier', function (): void {
    config(['operations.alerts.enabled' => true, 'operations.alerts.webhook_url' => 'https://ops.example.test/private-hook']);
    Http::fake(['*' => Http::sequence()->pushFailedConnection('private transport details')->push([], 202)]);
    $payload = ['incidents' => [['key' => 'worker', 'severity' => 'critical', 'title' => 'Worker', 'detail' => 'Offline']]];
    $service = app(OperationalIncidentService::class);
    expect(fn () => $service->evaluate($payload, false))->toThrow(RuntimeException::class, __('recovery.errors.webhook_connection'));
    $event = DB::table('operational_incidents')->sole();
    expect($event->notified_at)->toBeNull()->and($event->delivery_error)->not->toContain('private');
    expect($service->evaluate($payload, false)['payload']['incidents'][0]['event_id'])->toBe($event->notification_id);
});
