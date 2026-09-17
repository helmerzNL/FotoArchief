<?php

declare(strict_types=1);

if (getenv('FOTOARCHIEF_DISPOSABLE_WEBHOSTING_UPGRADE') !== '1') {
    throw new RuntimeException('Webhosting upgrade acceptance only runs against a disposable local fixture.');
}

$archive = $argv[1] ?? '';
if ($archive === '' || ! is_file($archive)) {
    throw new RuntimeException('Supply an existing webhosting release ZIP.');
}

$root = sys_get_temp_dir().'/fotoarchief-webhosting-upgrade-'.bin2hex(random_bytes(8));
$installed = $root.'/installed';
$staging = $root.'/staging';

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function copyTreePreservingInstallation(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (! $entry->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
        if ($relative === '.env'
            || str_starts_with($relative, '.env.')
            || str_starts_with($relative, 'storage/app/installation/')
            || str_starts_with($relative, 'storage/app/private/')
            || str_starts_with($relative, 'storage/logs/') && $relative !== 'storage/logs/.gitignore') {
            throw new RuntimeException('Release archive contains private installed-instance state: '.$relative);
        }

        $destination = $target.'/'.$relative;
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
            throw new RuntimeException('Cannot create fixture directory: '.dirname($destination));
        }
        if (! copy($entry->getPathname(), $destination)) {
            throw new RuntimeException('Cannot copy release file into disposable fixture: '.$relative);
        }
    }
}

try {
    mkdir($installed.'/storage/app/installation', 0700, true);
    mkdir($installed.'/storage/app/private/originals', 0700, true);
    file_put_contents($installed.'/.env', "APP_KEY=base64:fixture-key-that-must-survive\n");
    file_put_contents($installed.'/storage/app/installation/state.json', json_encode([
        'phase' => 'complete',
        'key' => 'base64:fixture-key-that-must-survive',
        'settings' => ['disk' => 'local', 'host' => 'postgres'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($installed.'/storage/app/installation/setup-code.txt', 'fixture-installer-lock');
    file_put_contents($installed.'/storage/app/private/originals/preserved.bin', 'private archive bytes');
    $before = [
        '.env' => hash_file('sha256', $installed.'/.env'),
        'state' => hash_file('sha256', $installed.'/storage/app/installation/state.json'),
        'lock' => hash_file('sha256', $installed.'/storage/app/installation/setup-code.txt'),
        'private' => hash_file('sha256', $installed.'/storage/app/private/originals/preserved.bin'),
    ];

    mkdir($staging, 0700, true);
    $zip = new ZipArchive;
    if ($zip->open($archive) !== true || ! $zip->extractTo($staging) || ! $zip->close()) {
        throw new RuntimeException('Cannot extract release archive into disposable fixture.');
    }
    copyTreePreservingInstallation($staging, $installed);

    $after = [
        '.env' => hash_file('sha256', $installed.'/.env'),
        'state' => hash_file('sha256', $installed.'/storage/app/installation/state.json'),
        'lock' => hash_file('sha256', $installed.'/storage/app/installation/setup-code.txt'),
        'private' => hash_file('sha256', $installed.'/storage/app/private/originals/preserved.bin'),
    ];
    if ($before !== $after) {
        throw new RuntimeException('Disposable webhosting upgrade changed private key, settings, installer lock or archive bytes.');
    }
    foreach (['artisan', 'public/index.php', 'vendor/autoload.php', 'VERSION'] as $required) {
        if (! is_file($installed.'/'.$required)) {
            throw new RuntimeException('Disposable webhosting upgrade missed release file: '.$required);
        }
    }

    echo "Disposable webhosting upgrade preserved APP_KEY, installation settings, installer lock and private archive bytes.\n";
} finally {
    removeTree($root);
}
