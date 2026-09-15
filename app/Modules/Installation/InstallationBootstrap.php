<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Illuminate\Foundation\Application;

final class InstallationBootstrap
{
    public static function boot(Application $app): void
    {
        $config = $app->make('config');
        if (! $config->get('installation.enabled')) {
            return;
        }
        $store = new InstallationStore($config->get('installation.path'));
        $state = $store->read();
        if ($state === null && ! $app->runningInConsole()) {
            $state = $store->initialize();
        }
        if ($state === null) {
            return;
        }
        $config->set('app.key', $state->key);
        if ($state->phase !== 'complete') {
            $config->set('session.driver', 'file');
            $config->set('cache.default', 'file');
            $config->set('queue.default', 'sync');
        } else {
            $state->settings?->apply($config);
        }
    }
}
