<?php

declare(strict_types=1);

use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = (string) getenv('FOTOARCHIEF_TEST_PGVECTOR_DATABASE');
$runId = $argv[1] ?? '';
$storageRoot = $argv[2] ?? '';
if ($database === '' || ! str_ends_with($database, '_pgvector_test') || $runId === '' || ! is_dir($storageRoot)) {
    fwrite(STDERR, "Explicit disposable database, operation ID and fixture storage are required.\n");
    exit(64);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(dirname(__DIR__).'/Fixtures');
$app->loadEnvironmentFrom('test-settings');
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => getenv('FOTOARCHIEF_TEST_PGVECTOR_HOST') ?: '127.0.0.1',
    'database.connections.pgsql.port' => getenv('FOTOARCHIEF_TEST_PGVECTOR_PORT') ?: 5432,
    'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => getenv('FOTOARCHIEF_TEST_PGVECTOR_USER') ?: 'fotoarchief',
    'database.connections.pgsql.password' => getenv('FOTOARCHIEF_TEST_PGVECTOR_PASSWORD') ?: '',
    'filesystems.disks.local.root' => $storageRoot,
]);
DB::purge('pgsql');
Http::preventStrayRequests();
Http::fake(['http://127.0.0.1:8088/v1/embed-image' => Http::response([
    'embedding' => [1, 0, 0], 'dimensions' => 3, 'model_space' => 'vector-workflow',
])]);
OperationRun::saved(function (OperationRun $run) use ($runId): void {
    if ($run->id === $runId && ($run->payload['cursor'] ?? 0) === 1) {
        DB::afterCommit(static function (): never {
            exit(73);
        });
    }
});
Queue::connection('ingest')->push(new ProcessAiIndexJob($runId));
$app->make(Kernel::class)->call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3, '--timeout' => 120]);
fwrite(STDERR, "Worker did not reach the interruption checkpoint.\n");
exit(65);
