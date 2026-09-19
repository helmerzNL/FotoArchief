<?php

declare(strict_types=1);

if ($argc < 3 || ! in_array($argv[1], ['create', 'verify'], true)) {
    fwrite(STDERR, "Usage: php scripts/release-asset-manifest.php create|verify <directory> [tag revision]\n");
    exit(2);
}

$mode = $argv[1];
$directory = realpath($argv[2]);
if ($directory === false || ! is_dir($directory)) {
    throw new RuntimeException('Release asset directory does not exist.');
}
$manifestPath = $directory.'/RELEASE-ASSETS.json';

if ($mode === 'create') {
    if ($argc !== 5 || ! preg_match('/^v\d+\.\d+\.\d+$/D', $argv[3]) || ! preg_match('/^[0-9a-f]{40}$/D', $argv[4])) {
        throw new RuntimeException('Create requires a valid tag and revision.');
    }
    $files = array_values(array_filter(scandir($directory) ?: [], static fn (string $name): bool => $name !== '.' && $name !== '..' && $name !== 'RELEASE-ASSETS.json'));
    sort($files, SORT_STRING);
    $assets = [];
    foreach ($files as $name) {
        $path = $directory.'/'.$name;
        if (! is_file($path)) {
            throw new RuntimeException("Release asset must be a regular file: {$name}");
        }
        $assets[] = ['name' => $name, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }
    file_put_contents($manifestPath, json_encode([
        'schema_version' => 1,
        'tag' => $argv[3],
        'revision' => $argv[4],
        'assets' => $assets,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo 'Recorded '.count($assets)." release assets.\n";
    exit(0);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['assets'] ?? null)) {
    throw new RuntimeException('Invalid release asset manifest.');
}
foreach ($manifest['assets'] as $asset) {
    $name = $asset['name'] ?? '';
    if (! is_string($name) || basename($name) !== $name || $name === 'RELEASE-ASSETS.json') {
        throw new RuntimeException('Unsafe release asset name.');
    }
    $path = $directory.'/'.$name;
    if (! is_file($path) || filesize($path) !== ($asset['bytes'] ?? null) || hash_file('sha256', $path) !== ($asset['sha256'] ?? null)) {
        throw new RuntimeException("Release asset verification failed: {$name}");
    }
}
echo 'Verified '.count($manifest['assets'])." release assets.\n";
