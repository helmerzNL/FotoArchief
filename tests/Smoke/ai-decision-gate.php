<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$doc = file_get_contents($root.'/docs/AI_CAPABILITY_DECISION.md');
if ($doc === false) {
    fwrite(STDERR, "Missing AI capability decision document.\n");
    exit(1);
}
$normalized = preg_replace('/\s+/', ' ', $doc) ?? $doc;

$required = [
    'No provider, model or vector index is enabled by default',
    'must not silently fall back from local processing to an external provider',
    'semantic image-content search is unavailable with an explicit operational error',
    'Vector results are candidates only',
    'Different model spaces are never mixed',
    'Maximum proof batch: 25 assets',
    'Maximum default AI derivative edge: 1024 pixels',
    'Vector result candidate cap before SQL authorization filtering: 250',
    'Public result page cap after authorization filtering: 24',
    'Synthetic timings, SQLite, mocks and caption-only comparisons are not accepted',
];

foreach ($required as $needle) {
    if (! str_contains($normalized, $needle)) {
        fwrite(STDERR, "AI decision gate missing required phrase: {$needle}\n");
        exit(1);
    }
}

if (preg_match('/automatic .*fallback/i', $normalized) && ! str_contains($normalized, 'never fallback')) {
    fwrite(STDERR, "AI decision gate must reject automatic provider fallback.\n");
    exit(1);
}

echo "AI decision gate smoke passed.\n";
