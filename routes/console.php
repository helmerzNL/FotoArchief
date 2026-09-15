<?php

declare(strict_types=1);

use App\Modules\Installation\InstallationStore;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
