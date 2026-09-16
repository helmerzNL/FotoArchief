<?php

declare(strict_types=1);

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('FOTOARCHIEF_DISPOSABLE_MANAGER') !== '1') {
    throw new RuntimeException('Manager acceptance is restricted to an explicitly disposable GitHub runner.');
}

function managerRequest(string $path, array $payload): array
{
    $curl = curl_init('http://127.0.0.1:13000'.$path);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: http://127.0.0.1:13000'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 300,
    ]);
    $body = curl_exec($curl);
    if ($body === false) {
        throw new RuntimeException('Disposable manager request failed: '.curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Disposable manager rejected '.$path.' (HTTP '.$status.').');
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

$environment = managerRequest('/api/environments', [
    'name' => 'FotoArchief disposable CI',
    'connectionType' => 'socket',
    'socketPath' => '/var/run/docker.sock',
]);
if (! is_int($environment['id'] ?? null)) {
    throw new RuntimeException('Manager did not return its created environment ID.');
}
$compose = file_get_contents(dirname(__DIR__, 2).'/deploy/compose.yaml');
$template = file_get_contents(dirname(__DIR__, 2).'/deploy/.env.example');
if ($compose === false || $template === false) {
    throw new RuntimeException('Deployment templates are missing.');
}
$values = [
    'COMPOSE_PROJECT_NAME' => 'fotoarchief-managed',
    'APP_IMAGE' => getenv('APP_IMAGE'),
    'APP_BIND_ADDRESS' => '127.0.0.1',
    'APP_HTTP_PORT' => '8082',
    'APP_URL' => 'http://127.0.0.1:8082',
    'DB_PASSWORD' => getenv('DB_PASSWORD'),
];
foreach ($values as $key => $value) {
    if (! is_string($value) || $value === '' || strpbrk($value, "\r\n") !== false) {
        throw new RuntimeException('Missing or invalid disposable fixture value: '.$key);
    }
    $template = preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', static fn (): string => $key.'='.$value, $template, -1, $count);
    if ($count !== 1 || ! is_string($template)) {
        throw new RuntimeException('Environment template must define '.$key.' exactly once.');
    }
}
managerRequest('/api/stacks?env='.$environment['id'], [
    'name' => 'fotoarchief-managed',
    'compose' => $compose,
    'rawEnvContent' => $template,
    'start' => true,
    'pull' => false,
    'build' => false,
]);
echo "Dockhand accepted the unchanged Compose template and substituted environment template.\n";
