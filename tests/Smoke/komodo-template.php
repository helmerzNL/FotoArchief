<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$template = file_get_contents($root.'/deploy/komodo-stack.example.toml');
if ($template === false) {
    throw new RuntimeException('Komodo stack template is missing.');
}

$required = [
    'file_paths = ["compose.yaml"]',
    'project_name = "fotoarchief"',
    'poll_for_updates = true',
    'auto_update = false',
    'APP_IMAGE=ghcr.io/helmerznl/fotoarchief:v',
    'APP_URL=https://fotoarchief.example.org',
    'TRUSTED_PROXIES=',
    'SESSION_SECURE_COOKIE=true',
    'DB_PASSWORD=REPLACE_WITH_PRIVATE_POSTGRES_PASSWORD',
];

foreach ($required as $needle) {
    if (! str_contains($template, $needle)) {
        throw new RuntimeException('Komodo template misses required fragment: '.$needle);
    }
}

$forbidden = ['ci-disposable', 'secret', 'password123', 'localhost:8080'];
foreach ($forbidden as $needle) {
    if (str_contains(strtolower($template), $needle)) {
        throw new RuntimeException('Komodo template contains a non-placeholder secret or local-only value: '.$needle);
    }
}

echo "Komodo template keeps manager import and update settings explicit.\n";
