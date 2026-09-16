<?php

declare(strict_types=1);

$file = $argv[1] ?? '';
$zip = new ZipArchive;
if ($file === '' || $zip->open($file) !== true) {
    throw new RuntimeException('Supply an existing webhosting release ZIP.');
}
$required = ['artisan', 'public/index.php', 'public/app.css', 'public/uploads.js', 'vendor/autoload.php', 'BUILD.json', 'DEPENDENCY-LICENSES.json', 'VERSION'];
foreach ($required as $name) {
    if ($zip->locateName($name) === false) {
        throw new RuntimeException('Missing release file: '.$name);
    }
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if ($name === false || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
        throw new RuntimeException('Unsafe archive path.');
    }
    if (in_array(explode('/', $name)[0], ['.git', 'tests', 'node_modules', '.github'], true)
        || $name === '.env' || str_starts_with($name, 'vendor/pestphp/')
        || str_starts_with($name, 'storage/app/installation/')
        || str_starts_with($name, 'storage/app/private/')
        || str_starts_with($name, 'storage/logs/') && $name !== 'storage/logs/.gitignore') {
        throw new RuntimeException('Unexpected private/development artifact: '.$name);
    }
}
$build = json_decode($zip->getFromName('BUILD.json'), true, 512, JSON_THROW_ON_ERROR);
if (trim($zip->getFromName('VERSION')) !== $build['version'] || ! preg_match('/^[a-f0-9]{40}$/D', $build['revision'])) {
    throw new RuntimeException('Release provenance mismatch.');
}
$installed = json_decode($zip->getFromName('vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode($zip->getFromName('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$actual = array_column($installed['packages'], 'version', 'name');
$expected = array_column($lock['packages'], 'version', 'name');
ksort($actual);
ksort($expected);
if ($installed['dev'] !== false || $actual !== $expected) {
    throw new RuntimeException('Installed dependencies differ from production lock.');
}
$zip->close();
echo 'Validated production archive v'.$build['version'].' from '.$build['revision'].' ('.count($actual)." locked packages).\n";
