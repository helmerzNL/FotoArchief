<?php

declare(strict_types=1);

$file = $argv[1] ?? '';
$zip = new ZipArchive;
if ($file === '' || $zip->open($file) !== true) {
    throw new RuntimeException('Supply an existing webhosting release ZIP.');
}
$required = ['artisan', 'public/index.php', 'public/app.css', 'public/uploads.js', 'vendor/autoload.php', 'BUILD.json', 'DEPENDENCY-LICENSES.json', 'VERSION', 'scripts/upgrade-compose.sh', 'lang/nl/ai.php', 'lang/nl/operations.php'];
foreach ($required as $name) {
    if ($zip->locateName($name) === false) {
        throw new RuntimeException('Missing release file: '.$name);
    }
}
$brandRoot = dirname(__DIR__, 2).'/public';
$brandFiles = ['theme.js', 'manifest.webmanifest', 'favicon.ico'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($brandRoot.'/brand', FilesystemIterator::SKIP_DOTS)) as $asset) {
    if ($asset->isFile()) {
        $brandFiles[] = str_replace('\\', '/', substr($asset->getPathname(), strlen($brandRoot) + 1));
    }
}
foreach ($brandFiles as $asset) {
    if ($zip->getFromName('public/'.$asset) !== file_get_contents($brandRoot.'/'.$asset)) {
        throw new RuntimeException('Missing or stale release branding asset: public/'.$asset);
    }
}
$catalogues = glob(dirname(__DIR__, 2).'/lang/nl/*.php');
if ($catalogues === false || $catalogues === []) {
    throw new RuntimeException('No source translation catalogues found.');
}
foreach ($catalogues as $catalogue) {
    $name = 'lang/nl/'.basename($catalogue);
    if ($zip->getFromName($name) !== file_get_contents($catalogue)) {
        throw new RuntimeException('Missing or stale release catalogue: '.$name);
    }
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if ($name === false || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
        throw new RuntimeException('Unsafe archive path.');
    }
    if (in_array(explode('/', $name)[0], ['.git', 'tests', 'node_modules', '.github'], true)
        || $name === '.env' || (str_starts_with($name, '.env.') && $name !== '.env.example')
        || str_starts_with($name, 'vendor/pestphp/')
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
