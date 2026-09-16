<?php

declare(strict_types=1);

use App\Modules\DataExchange\Services\DataExportService;
use App\Modules\DataExchange\Services\MetadataImportService;
use App\Modules\Installation\InstallationStore;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

Schedule::command('exchange:prune-exports')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('exchange:recover-imports')->everyFifteenMinutes()->withoutOverlapping();
