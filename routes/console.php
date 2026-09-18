<?php

declare(strict_types=1);

use App\Modules\Ai\Services\ExternalAiProvider;
use App\Modules\Ai\Services\LocalAiProvider;
use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Services\BackupRegisterService;
use App\Modules\ArchiveOperations\Services\OperationalAlertService;
use App\Modules\ArchiveOperations\Services\RestoreDrillService;
use App\Modules\ArchiveOperations\Services\SystemHeartbeatService;
use App\Modules\DataExchange\Services\DataExportService;
use App\Modules\DataExchange\Services\MetadataImportService;
use App\Modules\Ingest\Services\UploadSessionService;
use App\Modules\Installation\DeploymentMigrationCoordinator;
use App\Modules\Installation\InstallationStore;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('uploads:prune', function (UploadSessionService $sessions): void {
    $this->line((string) $sessions->prune());
})->purpose('Remove chunks from at most 100 closed or expired upload sessions; retain receipts');
Schedule::command('uploads:prune')->hourly()->withoutOverlapping();

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('installation:prepare {--quiet-code : Do not print the private installation code}', function (InstallationStore $store): int {
    $state = $store->initialize();
    if ($state->phase === 'complete') {
        $this->info('Installatie is al voltooid.');

        return 0;
    }
    if (! $this->option('quiet-code')) {
        $code = file_get_contents($store->directory.'/setup-code.txt');
        if ($code === false) {
            $this->error('Installatiecode kan niet worden gelezen. Herstel de private installatiemap.');

            return 1;
        }
        $this->line('Private installatiecode: '.trim($code));
    }

    return 0;
})->purpose('Prepare private first-start state; keep the displayed code confidential');

Artisan::command('installation:ready', function (InstallationStore $store): int {
    return $store->completed() ? 0 : 1;
})->purpose('Exit successfully only after onboarding has completed');

Artisan::command('installation:migrate-ready', function (InstallationStore $store, DeploymentMigrationCoordinator $coordinator): int {
    if (! $store->completed()) {
        $this->info('Eerste installatie is nog niet voltooid; automatische databasemigratie wordt overgeslagen.');

        return 0;
    }

    $this->info('Automatische databasemigratie wordt gecoördineerd.');
    try {
        $coordinator->migrate();
    } catch (Throwable $exception) {
        $this->line('<error>Automatische databasemigratie is mislukt; de container start niet totdat dit is opgelost.</error>');
        $this->line('<error>Fouttype: '.$exception::class.'</error>');

        return 1;
    }

    $this->info('Databaseschema is klaar voor app, worker en scheduler.');

    return 0;
})->purpose('Coordinate deployment migrations after onboarding before runtime processes start');

Artisan::command('installation:migration-status', function (InstallationStore $store, DeploymentMigrationCoordinator $coordinator): int {
    if (! $store->completed()) {
        $this->info('Eerste installatie is nog niet voltooid; deploymentmigraties zijn nog niet van toepassing.');

        return 0;
    }

    try {
        $status = $coordinator->status();
    } catch (Throwable $exception) {
        $this->line('<error>Migratiestatus kon niet veilig worden bepaald.</error>');
        $this->line('<error>Fouttype: '.$exception::class.'</error>');

        return 1;
    }

    if ($status->coordinatorActive) {
        $this->warn('Een ander proces voert momenteel deploymentmigraties uit.');

        return 1;
    }

    if (! $status->ready()) {
        /** @var list<string> $pending */
        $pending = $status->pendingMigrations ?? [];
        $this->error('Achterstallige databasemigraties: '.count($pending));
        foreach ($pending as $migration) {
            $this->line('  - '.$migration);
        }

        return 1;
    }

    $this->info('Databaseschema is actueel; er is geen migratiecoördinator actief.');

    return 0;
})->purpose('Report whether deployment migrations are active, pending or complete');

Artisan::command('exchange:prune-exports', function (DataExportService $exports): int {
    $pruned = $exports->prune();
    $recovered = $exports->recoverStalled();
    $this->info('Verlopen exportbestanden opgeruimd: '.$pruned);
    $this->info('Afgebroken exports vrijgegeven: '.$recovered);

    return 0;
})->purpose('Delete expired export artifacts and release exports abandoned by a stopped worker');

Artisan::command('exchange:recover-imports', function (MetadataImportService $imports): int {
    $recovered = $imports->recoverStalled();
    $this->info('Afgebroken imports vrijgegeven: '.$recovered);

    return 0;
})->purpose('Release metadata imports abandoned by a stopped worker');

Artisan::command('operations:heartbeat {role : worker or scheduler} {--state=ok : State label stored with the heartbeat}', function (SystemHeartbeatService $heartbeats): int {
    $role = (string) $this->argument('role');
    if (! in_array($role, ['worker', 'scheduler'], true)) {
        $this->error('Rol moet worker of scheduler zijn.');

        return 1;
    }

    $heartbeats->record($role, (string) $this->option('state'), [
        'command' => 'operations:heartbeat',
        'sapi' => PHP_SAPI,
    ]);
    $this->info("Heartbeat opgeslagen voor {$role}.");

    return 0;
})->purpose('Record an operational heartbeat for diagnostics');

Artisan::command('operations:check-alerts {--dry-run : Evaluate alert payload without sending}', function (OperationalAlertService $alerts): int {
    $result = $alerts->evaluate((bool) $this->option('dry-run'));
    $incidentCount = count($result['payload']['incidents'] ?? []);
    $this->info("Operationele meldingen gecontroleerd: {$incidentCount} incident(en), reden: {$result['reason']}.");

    return 0;
})->purpose('Evaluate diagnostics and send configured operational alerts');

Artisan::command('operations:register-backup {directory}', function (BackupRegisterService $backups): int {
    $backup = $backups->register((string) $this->argument('directory'));
    $this->info('Checksum-verified backup registered: '.$backup->id);

    return 0;
})->purpose('Verify a trusted local backup manifest and record evidence without claiming a restore');

Artisan::command('operations:restore-drill {backup} {--database=} {--directory=} {--confirm-empty-target}', function (RestoreDrillService $drills): int {
    $backup = BackupRecord::query()->findOrFail((string) $this->argument('backup'));
    $drill = $drills->run($backup, (string) $this->option('database'), (string) $this->option('directory'), (bool) $this->option('confirm-empty-target'));
    $this->info('Restore drill verified: '.$drill->getKey());
    $this->line(json_encode($drill->report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    return 0;
})->purpose('Restore only to a separate empty PostgreSQL database and new directory, then verify all originals');

Artisan::command('ai:probe-local', function (LocalAiProvider $provider): int {
    $capabilities = $provider->probe();
    $this->info('Lokale AI-provider bereikbaar.');
    $this->line('Modelruimte: '.(string) ($capabilities['model_space'] ?? 'onbekend'));
    $this->line('Dimensies: '.(string) ($capabilities['dimensions'] ?? 'onbekend'));

    return 0;
})->purpose('Probe the explicitly configured local or organisation-owned AI service');

Artisan::command('ai:probe-external', function (ExternalAiProvider $provider): int {
    $capabilities = $provider->probe();
    $this->info('Externe AI-provider bereikbaar met expliciete toestemming en budget.');
    $this->line('Modelruimte: '.(string) ($capabilities['model_space'] ?? 'onbekend'));
    $this->line('Dimensies: '.(string) ($capabilities['dimensions'] ?? 'onbekend'));

    return 0;
})->purpose('Probe the explicitly configured external AI service');

Schedule::command('exchange:prune-exports')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('exchange:recover-imports')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('operations:check-alerts')->hourly()->withoutOverlapping();
