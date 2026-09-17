<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function aiImageLoadFixture(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fotoarchief-ai-image-load-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700, true);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'.fotoarchief-disposable-ai-load', "disposable\n");

    return $directory;
}

function removeAiImageLoadFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

function runAiImageLoad(array $arguments, array $environment = []): Process
{
    $process = new Process(
        array_merge([PHP_BINARY, base_path('tests/Smoke/ai-image-load.php')], $arguments),
        base_path(),
        $environment + ['FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD' => '1'],
    );
    $process->setTimeout(120);
    $process->run();

    return $process;
}

it('refuses to run without the explicit disposable environment guard', function (): void {
    $fixture = aiImageLoadFixture();
    try {
        $process = runAiImageLoad(['--fixture-dir='.$fixture, '--images=1'], ['FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD' => '0']);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('set FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1');
    } finally {
        removeAiImageLoadFixture($fixture);
    }
});

it('refuses the removed workers option instead of pretending to test concurrency', function (): void {
    $fixture = aiImageLoadFixture();
    try {
        $process = runAiImageLoad(['--fixture-dir='.$fixture, '--images=1', '--workers=2']);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('intentionally single-process');
    } finally {
        removeAiImageLoadFixture($fixture);
    }
});

it('uploads and processes the requested number of generated binary images', function (): void {
    $fixture = aiImageLoadFixture();
    try {
        $process = runAiImageLoad(['--fixture-dir='.$fixture, '--images=2']);

        expect($process->getExitCode())->toBe(0);
        $metrics = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($metrics)
            ->toMatchArray([
                'scope' => 'single-process synthetic binary ingest smoke',
                'images_requested' => 2,
                'uploads_accepted' => 2,
                'processed_successfully' => 2,
                'failed' => 0,
                'private_previews_verified' => 2,
                'database' => 'sqlite::memory:',
                'storage' => 'unique disposable local fixture',
                'workers' => 'not exercised; use deployment queue runtime for concurrency proof',
            ])
            ->and($metrics['private_preview_bytes'])->toBeGreaterThan(0)
            ->and($metrics['production_50k_proof'])->toContain('blocked');
    } finally {
        removeAiImageLoadFixture($fixture);
    }
});
