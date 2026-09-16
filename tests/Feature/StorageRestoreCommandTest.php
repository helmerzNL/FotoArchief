<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('restores a real streamed tar without changing its bytes', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-restore-test-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/storage');
    try {
        $tar = new PharData($directory.'/fixture.tar');
        $bytes = random_bytes(128);
        $tar->addFromString('app/private/original.png', $bytes);
        unset($tar);
        $process = new Process([PHP_BINARY, base_path('scripts/restore-storage.php')], $directory);
        $process->setInput(File::get($directory.'/fixture.tar'));
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(File::get($directory.'/storage/app/private/original.png'))->toBe($bytes);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects files outside app before extracting any backup entry', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-restore-test-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/storage');
    try {
        $tar = new PharData($directory.'/fixture.tar');
        $tar->addFromString('app/private/original.png', 'unchanged');
        $tar->addFromString('outside.txt', 'unsafe');
        unset($tar);
        $process = new Process([PHP_BINARY, base_path('scripts/restore-storage.php')], $directory);
        $process->setInput(File::get($directory.'/fixture.tar'));
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Unsafe backup entry.')
            ->and(File::allFiles($directory.'/storage'))->toHaveCount(0);
    } finally {
        File::deleteDirectory($directory);
    }
});
