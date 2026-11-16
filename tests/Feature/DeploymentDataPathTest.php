<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * App-Guidance baseline §21: everything FotoArchief must not lose lives in plain folders
 * under one absolute DATA_PATH on the host, never in Docker volumes.
 */

/** @return list<array{source: string, target: string}> */
function composeMounts(string $file): array
{
    $mounts = [];
    $inVolumes = false;
    foreach (preg_split('/\R/', (string) file_get_contents(base_path($file))) ?: [] as $line) {
        if (preg_match('/^\s+volumes:\s*$/', $line) === 1) {
            $inVolumes = true;

            continue;
        }
        if ($inVolumes && preg_match('/^\s+#/', $line) === 1) {
            continue;
        }
        if ($inVolumes && preg_match('/^\s+-\s+["\']?(.+?):(\/[^:"\']*)(?::[a-z]+)?["\']?\s*$/', $line, $match) === 1) {
            $mounts[] = ['source' => $match[1], 'target' => $match[2]];

            continue;
        }
        $inVolumes = false;
    }

    return $mounts;
}

it('keeps every mount of both Compose files under DATA_PATH and declares no named volume', function (string $file): void {
    $compose = (string) file_get_contents(base_path($file));
    $mounts = composeMounts($file);

    expect($compose)->not->toMatch('/^volumes:/m')
        ->and($compose)->toContain('DATA_PATH: ${DATA_PATH:?Set DATA_PATH in .env to an absolute folder of the host}')
        ->and($mounts)->not->toBeEmpty();
    foreach ($mounts as $mount) {
        expect($mount['source'])->toStartWith('${DATA_PATH:?');
    }
    // The database folder is mounted by the database service only.
    $postgresMounts = array_filter($mounts, fn (array $mount): bool => str_ends_with(explode('}', $mount['source'], 2)[1] ?? '', '/postgres'));
    expect($postgresMounts)->toHaveCount(1)
        ->and(array_values($postgresMounts)[0]['target'])->toBe('/var/lib/postgresql/data');
})->with(['deploy/compose.yaml', 'docker-compose.yml']);

it('ships an absolute DATA_PATH example in every environment template', function (string $file): void {
    preg_match('/^DATA_PATH=(.*)$/m', (string) file_get_contents(base_path($file)), $match);

    expect($match[1] ?? '')->toStartWith('/');
})->with(['.env.example', 'deploy/.env.example', 'deploy/komodo-stack.example.toml']);

it('warns from the container entrypoint when DATA_PATH is missing or relative', function (?string $dataPath, bool $warns): void {
    $directory = sys_get_temp_dir().'/fotoarchief-entrypoint-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($directory);
    try {
        $environment = ['PATH' => (string) getenv('PATH'), 'DATA_PATH' => $dataPath ?? ''];
        $result = Process::path($directory)->env($environment)->run(['sh', base_path('deploy/entrypoint.sh'), 'true']);

        expect($result->successful())->toBeTrue();
        if ($warns) {
            expect($result->errorOutput())->toContain('WARNING: DATA_PATH');
        } else {
            expect($result->errorOutput())->not->toContain('DATA_PATH');
        }
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'absolute' => ['/mnt/user/appdata/fotoarchief', false],
    'relative' => ['./data', true],
    'missing' => [null, true],
]);

/**
 * Runs scripts/migrate-data.sh against a fake `docker` that records every call.
 *
 * @param  list<string>  $volumes  existing Docker volumes
 * @param  array<string, string>  $extra  additional environment
 * @return array{exit: int, output: string, calls: string, data: string}
 */
function migrateData(array $volumes, array $arguments = [], array $extra = [], ?Closure $prepare = null): array
{
    $stub = sys_get_temp_dir().'/fotoarchief-migrate-'.bin2hex(random_bytes(4));
    $data = $stub.'/data';
    File::ensureDirectoryExists($stub.'/bin');
    File::ensureDirectoryExists($stub.'/volumes');
    File::ensureDirectoryExists($data);
    foreach ($volumes as $volume) {
        File::put($stub.'/volumes/'.$volume, '');
    }
    File::put($stub.'/bin/docker', <<<'SH'
#!/bin/sh
echo "$*" >> "$STUB_DIR/calls.log"
case "$1" in
    volume)
        [ "$2" = inspect ] && [ -f "$STUB_DIR/volumes/$3" ] && exit 0
        exit 1
        ;;
    compose)
        case "$*" in
            *" ps "*) [ -f "$STUB_DIR/postgres-container" ] && cat "$STUB_DIR/postgres-container"; exit 0 ;;
        esac
        exit 0
        ;;
    inspect)
        cat "$STUB_DIR/postgres-mount" 2>/dev/null
        exit 0
        ;;
    run)
        case "$*" in
            *"diff -r"*) [ -f "$STUB_DIR/diff-fails" ] && exit 1; exit 0 ;;
        esac
        exit 0
        ;;
esac
exit 0
SH);
    chmod($stub.'/bin/docker', 0755);
    File::put($stub.'/calls.log', '');
    if ($prepare !== null) {
        $prepare($stub, $data);
    }
    try {
        // $extra first: array union keeps the left value, so a test can override DATA_PATH.
        $result = Process::path(base_path())->env($extra + [
            'PATH' => $stub.'/bin:'.getenv('PATH'),
            'STUB_DIR' => $stub,
            'DATA_PATH' => $data,
            'COMPOSE_PROJECT_NAME' => 'fotoarchief',
        ])->run(['sh', 'scripts/migrate-data.sh', ...$arguments]);

        return [
            'exit' => (int) $result->exitCode(),
            'output' => $result->output().$result->errorOutput(),
            'calls' => (string) file_get_contents($stub.'/calls.log'),
            'data' => $data,
            'marker' => is_file($data.'/.migrated-from-docker-volumes'),
        ];
    } finally {
        File::deleteDirectory($stub);
    }
}

it('does nothing when there are no old Docker volumes', function (): void {
    $run = migrateData([]);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Nothing to move')
        ->and($run['calls'])->not->toContain('stop')
        ->and($run['calls'])->not->toContain('run ');
});

it('only reports with --check and changes nothing', function (): void {
    $run = migrateData(['fotoarchief_app-storage', 'fotoarchief_postgres-data'], ['--check']);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('volume fotoarchief_postgres-data -> ')
        ->and($run['calls'])->not->toContain('stop')
        ->and($run['calls'])->not->toContain('run ')
        ->and($run['marker'])->toBeFalse();
});

it('moves the volumes with the stack stopped, checks each copy and keeps the volumes', function (): void {
    $run = migrateData(['fotoarchief_app-storage', 'fotoarchief_postgres-data', 'fotoarchief_valkey-data']);

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toContain('compose -f deploy/compose.yaml stop')
        ->and(substr_count($run['calls'], 'cp -a /from/. /to/'))->toBe(3)
        ->and(substr_count($run['calls'], 'diff -r /from /to'))->toBe(3)
        ->and($run['calls'])->toContain('fotoarchief_postgres-data:/from:ro')
        ->and($run['calls'])->not->toContain('volume rm')
        ->and($run['marker'])->toBeTrue()
        ->and($run['output'])->toContain('docker volume rm fotoarchief_postgres-data');
    // The stop comes before any copy.
    expect(strpos($run['calls'], ' stop'))->toBeLessThan(strpos($run['calls'], 'cp -a'));
});

it('refuses when DATA_PATH already holds a database and copies nothing', function (): void {
    $run = migrateData(['fotoarchief_postgres-data'], [], [], function (string $stub, string $data): void {
        File::ensureDirectoryExists($data.'/postgres');
        File::put($data.'/postgres/PG_VERSION', "16\n");
    });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('hold a database')
        ->and($run['calls'])->not->toContain('stop')
        ->and($run['calls'])->not->toContain('run ');
});

it('stops without a marker when a copy does not match its volume', function (): void {
    $run = migrateData(['fotoarchief_app-storage'], [], [], function (string $stub): void {
        File::put($stub.'/diff-fails', '');
    });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('differs from volume fotoarchief_app-storage')
        ->and($run['marker'])->toBeFalse()
        ->and($run['calls'])->not->toContain('volume rm');
});

it('is a no-op after a completed move although the old volumes still exist', function (): void {
    $run = migrateData(['fotoarchief_app-storage'], [], [], function (string $stub, string $data): void {
        File::put($data.'/.migrated-from-docker-volumes', "fotoarchief_app-storage\n");
    });

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Already moved')
        ->and($run['calls'])->toBe('');
});

it('refuses a relative DATA_PATH', function (): void {
    $run = migrateData(['fotoarchief_app-storage'], [], ['DATA_PATH' => 'data']);

    expect($run['exit'])->toBe(2)
        ->and($run['output'])->toContain('is relative')
        ->and($run['calls'])->toBe('');
});
