<?php

declare(strict_types=1);

use App\Modules\Installation\DeploymentMigrationCoordinator;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationState;
use App\Modules\Installation\InstallationStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

final class FakeDeploymentMigrationCoordinator implements DeploymentMigrationCoordinator
{
    public int $runs = 0;

    public ?Throwable $failure = null;

    public function migrate(): void
    {
        $this->runs++;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

beforeEach(function (): void {
    $this->installationDirectory = sys_get_temp_dir().'/fotoarchief-migration-test-'.bin2hex(random_bytes(8));
    config([
        'installation.enabled' => true,
        'installation.path' => $this->installationDirectory.'/installation',
    ]);
    app()->forgetInstance(InstallationStore::class);
    $this->store = app(InstallationStore::class);
    $this->store->initialize();
    $this->coordinator = new FakeDeploymentMigrationCoordinator;
    app()->instance(DeploymentMigrationCoordinator::class, $this->coordinator);
});

afterEach(function (): void {
    File::deleteDirectory($this->installationDirectory);
});

function markInstallationComplete(InstallationStore $store): void
{
    $settings = new InstallationSettings('postgres', 5432, 'fotoarchief', 'fotoarchief', 'private-db-password', 'prefer', 'local', '', '', '', '', '', true);
    $state = new InstallationState(
        id: 'deployment-migration-test',
        key: 'base64:'.base64_encode(str_repeat('a', 32)),
        codeHash: hash('sha256', 'private-installation-code'),
        phase: 'complete',
        settings: $settings,
        fingerprint: $settings->fingerprint('owner@example.test'),
    );
    $store->save($state);
}

it('keeps database-free onboarding available by skipping migrations before installation is complete', function (): void {
    expect(Artisan::call('installation:migrate-ready'))->toBe(0)
        ->and($this->coordinator->runs)->toBe(0)
        ->and(Artisan::output())->toContain('automatische databasemigratie wordt overgeslagen');
});

it('coordinates migrations after installation is complete before runtime services start', function (): void {
    markInstallationComplete($this->store);

    expect(Artisan::call('installation:migrate-ready'))->toBe(0)
        ->and($this->coordinator->runs)->toBe(1)
        ->and(Artisan::output())->toContain('Databaseschema is klaar');
});

it('fails closed and does not print secret-bearing exception messages when migration coordination fails', function (): void {
    markInstallationComplete($this->store);
    $this->coordinator->failure = new RuntimeException('sensitive-detail-that-must-not-be-printed');

    $exitCode = Artisan::call('installation:migrate-ready');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($this->coordinator->runs)->toBe(1)
        ->and($output)->toContain('Automatische databasemigratie is mislukt')
        ->and($output)->toContain(RuntimeException::class)
        ->and($output)->not->toContain('sensitive-detail-that-must-not-be-printed');
});
