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
    $directories = [];
    $files = [];
    foreach (new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::SELF_FIRST) as $name => $file) {
        $relative = substr(str_replace('\\', '/', $name), strlen($prefix));
        if (($relative !== 'app' && ! str_starts_with($relative, 'app/'))
            || str_contains($relative, '..') || $file->isLink()) {
            throw new RuntimeException('Unsafe backup entry.');
        }
        $target = 'storage/'.$relative;
        if (is_link($target)) {
            throw new RuntimeException('Restore target contains a symbolic link.');
        }
        if ($file->isDir()) {
            if (file_exists($target) && ! is_dir($target)) {
                throw new RuntimeException('Restore directory conflicts with an existing file.');
            }
            $directories[] = $target;

            continue;
        }
        if (file_exists($target)) {
            if ($relative === 'app/.gitignore' && is_file($target)
                && hash_file('sha256', $target) === hash_file('sha256', $name)) {
                continue;
            }
            throw new RuntimeException('Restore refuses to overwrite an existing file.');
        }
        $files[] = $relative;
    }
    foreach ($directories as $target) {
        if (! is_dir($target) && ! mkdir($target, 0700, true)) {
            throw new RuntimeException('Cannot create restore destination directory.');
        }
    }
    if ($files !== [] && ! $tar->extractTo('storage', $files, false)) {
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
