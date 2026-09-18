<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function createReleaseArchiveFixture(string $path, array $extraFiles = []): void
{
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL))->toBeTrue();

    $catalogues = [];
    foreach (File::glob(base_path('lang/nl/*.php')) as $catalogue) {
        $catalogues['lang/nl/'.basename($catalogue)] = File::get($catalogue);
    }
    expect($catalogues)->not->toBeEmpty();
    $branding = [];
    foreach (File::allFiles(public_path('brand')) as $asset) {
        $branding['public/brand/'.str_replace('\\', '/', $asset->getRelativePathname())] = $asset->getContents();
    }
    foreach (['theme.js', 'manifest.webmanifest', 'favicon.ico'] as $asset) {
        $branding['public/'.$asset] = File::get(public_path($asset));
    }
    $files = array_merge([
        'artisan' => '<?php echo "artisan";',
        'public/index.php' => '<?php echo "index";',
        'public/app.css' => 'body{}',
        'public/uploads.js' => 'console.log("uploads");',
        'vendor/autoload.php' => '<?php',
        'BUILD.json' => json_encode(['version' => '0.9.51', 'revision' => str_repeat('a', 40)], JSON_THROW_ON_ERROR),
        'DEPENDENCY-LICENSES.json' => '[]',
        'VERSION' => '0.9.51',
        '.env.example' => 'APP_KEY=',
        'scripts/upgrade-compose.sh' => '#!/bin/sh',
        'composer.lock' => json_encode(['packages' => [['name' => 'example/package', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR),
        'vendor/composer/installed.json' => json_encode(['dev' => false, 'packages' => [['name' => 'example/package', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR),
        'storage/logs/.gitignore' => "*\n!.gitignore\n",
    ], $catalogues, $branding, $extraFiles);

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

        $staleBranding = $directory.'/stale-branding.zip';
        createReleaseArchiveFixture($staleBranding, ['public/brand/tokens.css' => ':root{}']);
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($staleBranding);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Missing or stale release branding asset: public/brand/tokens.css');

        $missingFont = $directory.'/missing-font.zip';
        createReleaseArchiveFixture($missingFont);
        $zip = new ZipArchive;
        expect($zip->open($missingFont))->toBeTrue()
            ->and($zip->deleteName('public/brand/fonts/source-sans-regular.woff2'))->toBeTrue()
            ->and($zip->close())->toBeTrue();
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($missingFont);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Missing or stale release branding asset: public/brand/fonts/source-sans-regular.woff2');

        $staleCatalogue = $directory.'/stale-catalogue.zip';
        createReleaseArchiveFixture($staleCatalogue, ['lang/nl/operations.php' => '<?php return [];']);
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($staleCatalogue);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Missing or stale release catalogue: lang/nl/operations.php');

        $missingCatalogue = $directory.'/missing-catalogue.zip';
        createReleaseArchiveFixture($missingCatalogue);
        $zip = new ZipArchive;
        expect($zip->open($missingCatalogue))->toBeTrue()
            ->and($zip->deleteName('lang/nl/ai.php'))->toBeTrue()
            ->and($zip->close())->toBeTrue();
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($missingCatalogue);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Missing release file: lang/nl/ai.php');

        $privateState = $directory.'/private-state.zip';
        createReleaseArchiveFixture($privateState, [
            'storage/app/installation/state.json' => '{"phase":"complete","key":"base64:private"}',
        ]);
        [$status, $stdout, $stderr] = runReleaseArchiveValidator($privateState);
        expect($status)->not->toBe(0)
            ->and($stdout.$stderr)->toContain('Unexpected private/development artifact: storage/app/installation/state.json');

        foreach (['.env', '.env.production', '.env.example.backup'] as $index => $name) {
            $privateEnvironment = $directory.'/private-env-'.$index.'.zip';
            createReleaseArchiveFixture($privateEnvironment, [
                $name => 'APP_KEY=base64:private',
            ]);
            [$status, $stdout, $stderr] = runReleaseArchiveValidator($privateEnvironment);
            expect($status)->not->toBe(0)
                ->and($stdout.$stderr)->toContain('Unexpected private/development artifact: '.$name);
            [$status, $stdout, $stderr] = runWebhostingUpgradeValidator($privateEnvironment);
            expect($status)->not->toBe(0)
                ->and($stdout.$stderr)->toContain('Release archive contains private installed-instance state: '.$name);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});
