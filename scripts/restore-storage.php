<?php

declare(strict_types=1);

$directory = sys_get_temp_dir().'/fotoarchief-restore-'.bin2hex(random_bytes(12));
$archive = $directory.'/storage.tar';
$exitCode = 0;

try {
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create private restore directory.');
    }
    $out = fopen($archive, 'xb');
    if ($out === false) {
        throw new RuntimeException('Cannot create restore archive.');
    }
    try {
        if (stream_copy_to_stream(STDIN, $out) === false) {
            throw new RuntimeException('Cannot read restore archive.');
        }
    } finally {
        fclose($out);
    }
    $tar = new PharData($archive);
    $prefix = 'phar://'.str_replace('\\', '/', $archive).'/';
    foreach (new RecursiveIteratorIterator($tar) as $name => $file) {
        $relative = substr(str_replace('\\', '/', $name), strlen($prefix));
        if (! str_starts_with($relative, 'app/') || str_contains($relative, '..') || $file->isLink()) {
            throw new RuntimeException('Unsafe backup entry.');
        }
    }
    if (! $tar->extractTo('storage', null, false)) {
        throw new RuntimeException('Cannot extract restore archive.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Storage restore failed: '.$error->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    unset($tar);
    if (is_file($archive)) {
        unlink($archive);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
exit($exitCode);
