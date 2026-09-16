<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\SystemDiagnosticsService;
use Illuminate\Support\Facades\Hash;

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
    ]);
});

it('runs diagnostics service and reports valid structure', function (): void {
    $service = app(SystemDiagnosticsService::class);
    $diagnostics = $service->getAllDiagnostics();

    expect($diagnostics)->toBeArray();
    expect($diagnostics['php']['version'])->toBeString();
    expect($diagnostics['extensions']['extensions'])->toBeArray();
    expect($diagnostics['storage']['disks'])->toBeArray();
    expect($diagnostics['database']['connected'])->toBeTrue();
});
