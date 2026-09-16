<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Installation\InstallationStore;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class InstallationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstallationStore::class, fn ($app) => new InstallationStore($app['config']->get('installation.path')));
    }

    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'config:cache' && config('installation.enabled') && ! app(InstallationStore::class)->completed()) {
                throw new RuntimeException('Voltooi de installatie voordat je de configuratie cachet.');
            }
        });
    }
}
