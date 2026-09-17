<?php

declare(strict_types=1);

if (getenv('FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD') !== '1') {
    fwrite(STDERR, "Refusing image-load harness: set FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1 for an isolated disposable fixture.\n");
    exit(2);
}

$arguments = getopt('', ['fixture-dir:', 'images:', 'workers:']);
$fixture = $arguments['fixture-dir'] ?? '';
$images = filter_var($arguments['images'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
$workers = filter_var($arguments['workers'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
if (! is_string($fixture) || $fixture === '' || $images === false || $workers === false) {
    fwrite(STDERR, "Usage: FOTOARCHIEF_DISPOSABLE_AI_IMAGE_LOAD=1 php tests/Smoke/ai-image-load.php --fixture-dir=EMPTY_MARKED_DIR --images=1..100 --workers=1..8\n");
    exit(2);
}
$real = realpath($fixture);
if ($real === false || ! is_file($real.DIRECTORY_SEPARATOR.'.fotoarchief-disposable-ai-load') || array_diff(scandir($real) ?: [], ['.', '..', '.fotoarchief-disposable-ai-load']) !== []) {
    fwrite(STDERR, "Refusing image-load harness: fixture directory must be empty except for .fotoarchief-disposable-ai-load.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/artisan').' test '
    .escapeshellarg('tests/Feature/Operations/AiAsyncProcessingTest.php').' '
    .escapeshellarg('tests/Feature/Operations/AiEmbeddingIndexTest.php').' --compact';
$started = hrtime(true);
$exit = 0;
passthru($command, $exit);
$elapsedMs = round((hrtime(true) - $started) / 1_000_000, 2);
printf("Isolated metadata/job regression completed: images=%d workers=%d elapsed_ms=%.2f.\n", $images, $workers, $elapsedMs);
fwrite(STDERR, "This bounded harness exercises metadata and queued-job fixtures only; it is not image-binary load proof. A representative corpus and runtime are still required for 50k/concurrency acceptance.\n");
exit($exit);
