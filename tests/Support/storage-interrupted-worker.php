<?php

declare(strict_types=1);

use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageRelocation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $root, $runId, $boundary] = $argv;
if (! str_starts_with(basename($root), 'fotoarchief-storage-process-') || ! is_file($root.'/.disposable')
    || ! is_file($root.'/database.sqlite') || ! in_array($boundary, ['receipt', 'cursor'], true)) {
    fwrite(STDERR, "A marked disposable storage fixture and known interruption boundary are required.\n");
    exit(64);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(dirname(__DIR__).'/Fixtures');
$app->loadEnvironmentFrom('test-settings');
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $root.'/database.sqlite',
    'filesystems.disks.local.root' => $root.'/source',
    'filesystems.disks.recovery' => ['driver' => 'local', 'root' => $root.'/target', 'throw' => true],
]);
DB::purge('sqlite');
$interrupt = static function (): void {
    DB::afterCommit(static function (): never {
        exit(73);
    });
};
if ($boundary === 'receipt') {
    StorageRelocation::saved(static function (StorageRelocation $relocation) use ($interrupt): void {
        if ($relocation->is_verified) {
            $interrupt();
        }
    });
} else {
    OperationRun::saved(static function (OperationRun $run) use ($runId, $interrupt): void {
        if ($run->id === $runId && isset($run->payload['cursor'])) {
            $interrupt();
        }
    });
}
Queue::connection('ingest')->push(new StorageCopyJob($runId));
$app->make(Kernel::class)->call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3, '--timeout' => 120]);
fwrite(STDERR, "Worker did not reach the requested interruption boundary.\n");
exit(65);
