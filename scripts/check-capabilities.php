<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root.'/capabilities.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

if (($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['capabilities'] ?? null)) {
    throw new RuntimeException('Invalid capability manifest schema.');
}

$version = trim((string) file_get_contents($root.'/VERSION'));
if (($manifest['product_version'] ?? null) !== $version) {
    throw new RuntimeException('Capability manifest product_version must equal VERSION.');
}

$ids = [];
foreach ($manifest['capabilities'] as $capability) {
    $id = $capability['id'] ?? null;
    $status = $capability['status'] ?? null;
    if (! is_string($id) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || isset($ids[$id])) {
        throw new RuntimeException('Capability IDs must be unique kebab-case values.');
    }
    if (! in_array($status, ['shipped', 'conditional', 'experimental'], true)) {
        throw new RuntimeException("Invalid status for capability {$id}.");
    }
    if ($status === 'conditional' && empty($capability['requires'])) {
        throw new RuntimeException("Conditional capability {$id} must document requirements.");
    }
    if (empty($capability['evidence']) || ! is_array($capability['evidence'])) {
        throw new RuntimeException("Capability {$id} must name evidence.");
    }
    foreach ($capability['evidence'] as $path) {
        if (! is_string($path) || ! file_exists($root.'/'.$path)) {
            throw new RuntimeException("Missing capability evidence: {$path}");
        }
    }
    $ids[$id] = true;
}

$boundaries = $manifest['acceptance_boundaries'] ?? null;
if (! is_array($boundaries) || $boundaries === []) {
    throw new RuntimeException('Acceptance boundaries must remain explicit.');
}
foreach ($boundaries as $boundary) {
    if (! filter_var($boundary['issue'] ?? null, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Every acceptance boundary must link to its tracking issue.');
    }
}

$lines = [
    '# Capability register / Functieregister',
    '',
    '> Generated from `capabilities.json`; run `php scripts/check-capabilities.php --write` after changing capabilities.',
    '',
    "Product version / Productversie: **{$version}**",
    '',
    '| Capability | Status | Requirements / Vereisten | Evidence / Bewijs |',
    '| --- | --- | --- | --- |',
];
foreach ($manifest['capabilities'] as $capability) {
    $requirements = implode('; ', $capability['requires'] ?? ['-']);
    $evidence = implode(', ', array_map(
        static fn (string $path): string => sprintf('[`%s`](../%s)', $path, $path),
        $capability['evidence'],
    ));
    $lines[] = sprintf('| `%s` | %s | %s | %s |', $capability['id'], $capability['status'], $requirements, $evidence);
}
$lines[] = '';
$lines[] = '## External acceptance boundaries / Externe acceptatiegrenzen';
$lines[] = '';
foreach ($boundaries as $boundary) {
    $lines[] = sprintf('- `%s`: %s', $boundary['id'], $boundary['issue']);
}
$lines[] = '';
$generated = implode("\n", $lines);
$target = $root.'/docs/CAPABILITIES.md';

if (in_array('--write', $argv, true)) {
    file_put_contents($target, $generated);
    echo "Updated docs/CAPABILITIES.md\n";
    exit(0);
}

if (! is_file($target) || file_get_contents($target) !== $generated) {
    throw new RuntimeException('docs/CAPABILITIES.md is stale; run php scripts/check-capabilities.php --write.');
}

echo "Capability manifest and generated documentation are consistent.\n";
