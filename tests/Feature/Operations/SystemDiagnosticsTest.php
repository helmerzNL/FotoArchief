<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\SystemHeartbeat;
use App\Modules\ArchiveOperations\Services\OperationalAlertService;
use App\Modules\ArchiveOperations\Services\SystemDiagnosticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->artisan('migrate');

    $this->manageUsersPermission = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);
    $this->viewAuditPermission = Permission::query()->firstOrCreate(['key' => 'audit.view'], ['name' => 'Audit View']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([$this->manageUsersPermission->id, $this->viewAuditPermission->id, $this->viewAssetsPermission->id]);

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
});

it('redirects unauthenticated users from diagnostics endpoint', function (): void {
    $response = $this->get('/admin/operations/diagnostics');
    $response->assertRedirect('/login');
});

it('denies access to users without management or audit permissions', function (): void {
    $response = $this->actingAs($this->viewer)->get('/admin/operations/diagnostics');
    $response->assertStatus(403);
});

it('renders complete system diagnostics for administrators without leaking secrets', function (): void {
    $response = $this->actingAs($this->admin)->get('/admin/operations/diagnostics');

    $response->assertStatus(200);
    $response->assertSee('Systeemdiagnose', false);
    $response->assertSee('PHP & Omgeving', false);
    $response->assertSee('PHP Extensies', false);
    $response->assertSee('Opslag', false);
    $response->assertSee('Database & Migraties', false);
    $response->assertSee('Limieten & Geheugen', false);
    $response->assertSee('Malware Scanner', false);
    $response->assertSee('Worker & Wachtrij', false);

    // Ensure no secrets leaked
    $content = $response->getContent();
    expect($content)->not->toContain('secret12345');
    expect($content)->not->toContain(config('app.key'));
});

it('provides json diagnostics payload when requested', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/admin/operations/diagnostics');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'overall_status',
        'timestamp',
        'php' => ['status', 'version', 'os'],
        'extensions' => ['status', 'extensions'],
        'storage' => ['status', 'disks'],
        'database' => ['status', 'driver', 'connected'],
        'limits' => ['status', 'php_upload_max_filesize'],
        'scanner' => ['status', 'scanner'],
        'worker' => ['status', 'queue_driver'],
        'activity' => ['status', 'roles'],
    ]);
});

it('runs diagnostics service and reports valid structure', function (): void {
    $service = app(SystemDiagnosticsService::class);
    $diagnostics = $service->getAllDiagnostics();

    expect($diagnostics)->toBeArray();
    expect($diagnostics['php']['version'])->toBeString();
    expect($diagnostics['extensions']['extensions'])->toBeArray();
    expect($diagnostics['storage']['disks'])->toBeArray();
    expect($diagnostics['database']['connected'])->toBeTrue()
        ->and($diagnostics['activity']['roles'])->toHaveKeys(['worker', 'scheduler']);
});

it('records scheduler and worker heartbeats for diagnostics', function (): void {
    expect($this->artisan('operations:heartbeat', ['role' => 'scheduler'])->run())->toBe(0)
        ->and($this->artisan('operations:heartbeat', ['role' => 'worker', '--state' => 'starting'])->run())->toBe(0);

    $diagnostics = app(SystemDiagnosticsService::class)->getAllDiagnostics();

    expect($diagnostics['activity']['status'])->toBe('ok')
        ->and($diagnostics['activity']['roles']['scheduler']['seen'])->toBeTrue()
        ->and($diagnostics['activity']['roles']['scheduler']['stale'])->toBeFalse()
        ->and($diagnostics['activity']['roles']['worker']['state'])->toBe('starting');
});

it('reports stale heartbeats and returns to healthy after restored worker and scheduler heartbeats', function (): void {
    SystemHeartbeat::query()->create([
        'role' => 'scheduler',
        'state' => 'ok',
        'last_seen_at' => now()->subMinutes(6),
    ]);
    SystemHeartbeat::query()->create([
        'role' => 'worker',
        'state' => 'failed',
        'last_seen_at' => now()->subMinutes(7),
        'details' => ['exception' => RuntimeException::class],
    ]);

    $diagnostics = app(SystemDiagnosticsService::class)->getAllDiagnostics();
    expect($diagnostics['activity']['status'])->toBe('warning')
        ->and($diagnostics['activity']['stale_roles'])->toContain('scheduler', 'worker')
        ->and($diagnostics['activity']['roles']['worker']['state'])->toBe('failed');

    expect($this->artisan('operations:heartbeat', ['role' => 'scheduler'])->run())->toBe(0)
        ->and($this->artisan('operations:heartbeat', ['role' => 'worker', '--state' => 'processed'])->run())->toBe(0);

    $restored = app(SystemDiagnosticsService::class)->getAllDiagnostics();
    expect($restored['activity']['status'])->toBe('ok')
        ->and($restored['activity']['stale_roles'])->toBe([])
        ->and($restored['activity']['roles']['worker']['state'])->toBe('processed')
        ->and($restored['activity']['roles']['worker']['stale'])->toBeFalse();
});

it('sends configured operational alert webhooks without leaking secrets', function (): void {
    Http::fake([
        'https://ops.example.test/fotoarchief' => Http::response(['ok' => true], 202),
    ]);
    config([
        'operations.alerts.enabled' => true,
        'operations.alerts.webhook_url' => 'https://ops.example.test/fotoarchief',
        'operations.alerts.minimum_severity' => 'critical',
        'ingest.scanner' => 'clamav',
        'ingest.clamav_host' => '127.0.0.1',
        'ingest.clamav_port' => 9,
        'ingest.clamav_timeout' => 1,
    ]);

    $this->artisan('operations:check-alerts')->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://ops.example.test/fotoarchief'
            && ($payload['application'] ?? null) === config('app.name')
            && collect($payload['incidents'] ?? [])->contains(
                fn (array $incident): bool => $incident['key'] === 'scanner'
                    && $incident['severity'] === 'critical'
            )
            && ! str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'secret12345');
    });
});

it('dry-runs operational alerts without calling the webhook', function (): void {
    Http::fake();
    config([
        'operations.alerts.enabled' => true,
        'operations.alerts.webhook_url' => 'https://ops.example.test/fotoarchief',
        'ingest.scanner' => 'none',
    ]);

    $this->artisan('operations:check-alerts', ['--dry-run' => true])->assertExitCode(0);

    Http::assertNothingSent();
});

it('logs disabled operational alerts without calling the webhook', function (): void {
    Http::fake();
    Log::spy();
    config([
        'operations.alerts.enabled' => false,
        'operations.alerts.webhook_url' => 'https://ops.example.test/fotoarchief',
        'operations.alerts.minimum_severity' => 'warning',
        'ingest.scanner' => 'none',
    ]);

    $result = app(OperationalAlertService::class)->evaluate();

    expect($result['sent'])->toBeFalse()
        ->and($result['reason'])->toBe('disabled')
        ->and($result['payload']['incidents'])->not->toBeEmpty();
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Operationele Vistora melding gedetecteerd, verzending staat uit.'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secret12345')
    );
});

it('deduplicates unchanged incidents and preserves failed deliveries for retry', function (): void {
    Http::fake([
        'https://ops.example.test/fotoarchief' => Http::sequence()
            ->push(['ok' => true], 202)
            ->push('down', 500)
            ->push(['ok' => true], 202),
    ]);
    config([
        'operations.alerts.enabled' => true,
        'operations.alerts.webhook_url' => 'https://ops.example.test/fotoarchief',
        'operations.alerts.minimum_severity' => 'warning',
        'ingest.scanner' => 'none',
    ]);

    expect(app(OperationalAlertService::class)->evaluate()['sent'])->toBeTrue()
        ->and(app(OperationalAlertService::class)->evaluate()['reason'])->toBe('deduplicated');

    Http::assertSentCount(1);
    DB::table('operational_incidents')->update(['notified_at' => null]);

    expect(fn () => app(OperationalAlertService::class)->evaluate())
        ->toThrow(RuntimeException::class, 'Operations alert webhook failed with status 500.');

    Http::assertSentCount(2);
    expect(app(OperationalAlertService::class)->evaluate()['sent'])->toBeTrue();
    Http::assertSentCount(3);
});
