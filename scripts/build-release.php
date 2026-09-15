<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['output:', 'composer:']);
$output = $options['output'] ?? $root.'/dist';
$composer = $options['composer'] ?? 'composer';
$temporary = sys_get_temp_dir().'/fotoarchief-release-'.bin2hex(random_bytes(8));
$exitCode = 0;

function run(array $command, string $cwd): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $cwd);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start release command.');
    }
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $result === false) {
        throw new RuntimeException('Release command failed: '.implode(' ', $command));
    }

    return $result;
}

function removeTemporary(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function archive(string $source, string $destination): void
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('Release cannot contain symbolic links.');
        }
        if ($entry->isFile()) {
            $files[] = substr($entry->getPathname(), strlen($source) + 1);
        }
    }
    sort($files, SORT_STRING);
    $zip = new ZipArchive;
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create archive: '.$destination);
    }
    foreach ($files as $file) {
        $name = str_replace('\\', '/', $file);
        if (! $zip->addFile($source.'/'.$file, $name)
            || ! $zip->setMtimeName($name, 315532800)
            || ! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
            throw new RuntimeException('Cannot archive: '.$name);
        }
    }
    if (! $zip->close()) {
        throw new RuntimeException('Cannot finish archive: '.$destination);
    }
}

try {
    if (! extension_loaded('zip')) {
        throw new RuntimeException('The release builder requires ext-zip.');
    }
    if (trim(run(['git', 'status', '--porcelain', '--untracked-files=no'], $root)) !== '') {
        throw new RuntimeException('Commit tracked changes first: packages must describe one exact revision.');
    }
    if (! is_dir($output) && ! mkdir($output, 0700, true)) {
        throw new RuntimeException('Cannot create output directory.');
    }
    mkdir($temporary, 0700);
    $source = $temporary.'/source';
    mkdir($source, 0700);
    $revision = trim(run(['git', 'rev-parse', 'HEAD'], $root));
    run(['git', 'archive', '--format=zip', '--output='.$temporary.'/source.zip', $revision], $root);
    $zip = new ZipArchive;
    if ($zip->open($temporary.'/source.zip') !== true || ! $zip->extractTo($source) || ! $zip->close()) {
        throw new RuntimeException('Cannot extract committed source.');
    }
    $version = trim(file_get_contents($source.'/VERSION'));
    if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
        throw new RuntimeException('Invalid release VERSION.');
    }
    $package = $temporary.'/webhosting';
    mkdir($package, 0700);
    $allowed = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'docs'];
    $allowedFiles = ['artisan', 'composer.json', 'composer.lock', 'VERSION', 'README.md', '.env.example', 'LICENSE'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
        $parts = explode('/', $relative);
        if ($entry->isLink() || ! $entry->isFile()
            || (! in_array($parts[0], $allowed, true) && ! in_array($relative, $allowedFiles, true))) {
            continue;
        }
        if (($parts[0] === 'storage' || str_starts_with($relative, 'bootstrap/cache/'))
            && basename($relative) !== '.gitignore') {
            throw new RuntimeException('Committed runtime state must not enter a release: '.$relative);
        }
        if (basename($relative) === '.env' || str_ends_with($relative, '/setup-code.txt')) {
            throw new RuntimeException('Private configuration found in release source.');
        }
        $target = $package.'/'.$relative;
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        copy($entry->getPathname(), $target);
    }
    $composerCommand = str_ends_with($composer, '.phar') ? [PHP_BINARY, $composer] : [$composer];
    run([...$composerCommand, 'install', '--no-dev', '--no-scripts', '--no-interaction', '--prefer-dist', '--optimize-autoloader', '--no-progress'], $package);
    run([...$composerCommand, 'check-platform-reqs', '--no-dev'], $package);
    $licenses = run([...$composerCommand, 'licenses', '--format=json', '--no-dev'], $package);
    file_put_contents($package.'/DEPENDENCY-LICENSES.json', $licenses);
    file_put_contents($package.'/BUILD.json', json_encode(['version' => $version, 'revision' => $revision], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $deploy = $temporary.'/deploy';
    mkdir($deploy, 0700);
    copy($source.'/deploy/compose.yaml', $deploy.'/compose.yaml');
    copy($source.'/deploy/.env.example', $deploy.'/.env.example');
    copy($source.'/docs/DEPLOYMENT_STACKS.md', $deploy.'/DEPLOYMENT_STACKS.md');
    copy($package.'/BUILD.json', $deploy.'/BUILD.json');
    $names = ["fotoarchief-v{$version}-webhosting.zip", "fotoarchief-v{$version}-deploy.zip"];
    foreach ($names as $name) {
        if (file_exists($output.'/'.$name)) {
            throw new RuntimeException('Refusing to replace release artifact: '.$name);
        }
    }
    archive($package, $temporary.'/'.$names[0]);
    archive($deploy, $temporary.'/'.$names[1]);
    $checksums = '';
    foreach ($names as $name) {
        $checksums .= hash_file('sha256', $temporary.'/'.$name).'  '.$name."\n";
    }
    foreach ($names as $name) {
        if (! copy($temporary.'/'.$name, $output.'/'.$name)) {
            throw new RuntimeException('Cannot persist release artifact: '.$name);
        }
    }
    file_put_contents($output.'/SHA256SUMS', $checksums);
    echo $checksums;
} catch (Throwable $error) {
    fwrite(STDERR, 'Release failed: '.$error->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_dir($temporary)) {
        removeTemporary($temporary);
    }

    exit($exitCode);
}
