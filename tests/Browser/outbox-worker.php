<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Kernel::class);

while (true) {
    if ($kernel->call('outbox:dispatch') !== 0) {
        throw new RuntimeException($kernel->output());
    }

    usleep(100_000);
}
