<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function extractRelease(string $archive, string $target): string
{
    $zip = new ZipArchive;
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open release ZIP.');
    }
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (! is_string($name) || str_contains($name, '..') || str_contains($name, '\\')
            || str_starts_with($name, '/') || str_contains($name, ':') || $name === '.env'
            || str_starts_with($name, 'storage/app/installation/')
            || str_starts_with($name, 'storage/app/private/')) {
            throw new RuntimeException('Release contains an unsafe path or installed private data.');
        }
    }
    $version = trim((string) $zip->getFromName('VERSION'));
    if (! preg_match('/^\d+\.\d+\.\d+$/D', $version) || ! $zip->extractTo($target)) {
        throw new RuntimeException('Invalid release version or extraction failure.');
    }
    $zip->close();

    return $version;
}

function privateSnapshot(string $installed): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($installed.'/storage/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $hashes[substr($file->getPathname(), strlen($installed))] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($hashes);

    return $hashes;
}

function releaseLoopbackAddress(): string
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate loopback acceptance port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (! is_string($address)) {
        throw new RuntimeException('Cannot determine loopback acceptance port.');
    }

    return $address;
}

function releaseArtisan(string $installed, array $environment, array $arguments): void
{
    (new Process([PHP_BINARY, 'artisan', ...$arguments], $installed, $environment, timeout: 120))->mustRun();
}

function startReleaseServer(string $installed, array $environment, ?string $router = null): Process
{
    $address = parse_url($environment['SMOKE_URL'], PHP_URL_HOST).':'.parse_url($environment['SMOKE_URL'], PHP_URL_PORT);
    $arguments = ['-t', 'public', ...($router === null ? [] : [$router])];
    $process = new Process([PHP_BINARY, '-d', 'upload_max_filesize=100M', '-d', 'post_max_size=110M', '-S', $address, ...$arguments], $installed, $environment, timeout: null);
    $process->start();
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $curl = curl_init($environment['SMOKE_URL'].'/up');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        curl_exec($curl);
        if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200) {
            return $process;
        }
        if (! $process->isRunning()) {
            throw new RuntimeException('Fixture HTTP server failed: '.$process->getErrorOutput());
        }
        usleep(100000);
    }
    $process->stop();
    throw new RuntimeException('Fixture HTTP server did not become responsive.');
}

function runReleaseOnboarding(string $installed, array $environment, string $console = 'artisan'): void
{
    $worker = null;
    $acceptance = new Process([PHP_BINARY, __DIR__.'/http-onboarding.php'], $installed, $environment, timeout: 150);
    try {
        $acceptance->start();
        for ($attempt = 0; $attempt < 600 && $acceptance->isRunning(); $attempt++) {
            $statePath = dirname($environment['SMOKE_SETUP_CODE_FILE']).'/state.json';
            $state = is_file($statePath)
                ? json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR)
                : [];
            if (($state['phase'] ?? null) === 'complete') {
                $worker = new Process([PHP_BINARY, $console, 'queue:work', 'ingest', '--sleep=1', '--tries=3'], $installed, $environment, timeout: null);
                $worker->start();
                break;
            }
            usleep(100000);
        }
        $acceptance->wait();
        if (! $acceptance->isSuccessful()) {
            throw new RuntimeException($acceptance->getErrorOutput().$acceptance->getOutput().($worker?->getErrorOutput() ?? ''));
        }
        echo $acceptance->getOutput();
    } finally {
        $acceptance->stop();
        $worker?->stop();
    }
}

function removeReleaseFixture(string $root): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
