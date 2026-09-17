<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function createReleaseArchiveFixture(string $path, array $extraFiles = []): void
{
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL))->toBeTrue();

    $files = array_merge([
        'artisan' => '<?php echo "artisan";',
        'public/index.php' => '<?php echo "index";',
        'public/app.css' => 'body{}',
        'public/uploads.js' => 'console.log("uploads");',
        'vendor/autoload.php' => '<?php',
        'BUILD.json' => json_encode(['version' => '0.9.51', 'revision' => str_repeat('a', 40)], JSON_THROW_ON_ERROR),
        'DEPENDENCY-LICENSES.json' => '[]',
        'VERSION' => '0.9.51',
        'scripts/upgrade-compose.sh' => '#!/bin/sh',
        'composer.lock' => json_encode(['packages' => [['name' => 'example/package', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR),
        'vendor/composer/installed.json' => json_encode(['dev' => false, 'packages' => [['name' => 'example/package', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR),
        'storage/logs/.gitignore' => "*\n!.gitignore\n",
    ], $extraFiles);

    foreach ($files as $name => $contents) {
        expect($zip->addFromString($name, $contents))->toBeTrue();
    }
    expect($zip->close())->toBeTrue();
}

function runReleaseArchiveValidator(string $archive): array
{
    $command = [PHP_BINARY, base_path('tests/Smoke/release-archive.php'), $archive];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    expect($process)->toBeResource();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function runWebhostingUpgradeValidator(string $archive): array
{
    $command = [PHP_BINARY, base_path('tests/Smoke/webhosting-upgrade.php'), $archive];
    $environment = array_filter(getenv(), is_string(...));
    $environment['FOTOARCHIEF_DISPOSABLE_WEBHOSTING_UPGRADE'] = '1';
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        $environment,
    );
    expect($process)->toBeResource();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('accepts production archives and refuses private installed-instance state', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-release-archive-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);

    try {
        $valid = $directory.'/valid.zip';
        createReleaseArchiveFixture($valid);
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($valid);
        expect($status)->toBe(0)
            ->and($stdout)->toContain('Validated production archive v0.9.51')
            ->and($stderr)->toBe('');
        [$status, $stdout, $stderr] = runWebhostingUpgradeValidator($valid);
        expect($status)->toBe(0)
            ->and($stdout)->toContain('Disposable webhosting upgrade preserved APP_KEY')
            ->and($stderr)->toBe('');

        $privateState = $directory.'/private-state.zip';
        createReleaseArchiveFixture($privateState, [
            'storage/app/installation/state.json' => '{"phase":"complete","key":"base64:private"}',
        ]);
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($privateState);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Unexpected private/development artifact: storage/app/installation/state.json');

        $privateEnvironment = $directory.'/private-env.zip';
        createReleaseArchiveFixture($privateEnvironment, [
            '.env.production' => 'APP_KEY=base64:private',
        ]);
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($privateEnvironment);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Unexpected private/development artifact: .env.production');
    } finally {
        File::deleteDirectory($directory);
    }
});
