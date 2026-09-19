<?php

declare(strict_types=1);

it('keeps the capability manifest and generated documentation consistent', function (): void {
    $process = proc_open(
        [PHP_BINARY, base_path('scripts/check-capabilities.php')],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );
    expect($process)->toBeResource();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0)
        ->and($stdout)->toContain('Capability manifest and generated documentation are consistent.')
        ->and($stderr)->toBe('');
});

it('creates and verifies a deterministic release asset inventory', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-release-manifest-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/one.txt', 'one');
    file_put_contents($directory.'/two.zip', 'two');
    $script = base_path('scripts/release-asset-manifest.php');

    try {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' create '.escapeshellarg($directory).' v1.2.3 '.str_repeat('a', 40), $create, $createStatus);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' verify '.escapeshellarg($directory).' 2>&1', $verify, $verifyStatus);
        expect($createStatus)->toBe(0)
            ->and($verifyStatus)->toBe(0)
            ->and(implode("\n", $verify))->toContain('Verified 2 release assets.');

        file_put_contents($directory.'/one.txt', 'tampered');
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' verify '.escapeshellarg($directory).' 2>&1', $tampered, $tamperedStatus);
        expect($tamperedStatus)->not->toBe(0)
            ->and(implode("\n", $tampered))->toContain('Release asset verification failed: one.txt');
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
