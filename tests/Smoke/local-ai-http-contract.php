<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    header('Content-Type: application/json');

    if ($path === '/v1/capabilities') {
        echo json_encode([
            'provider_kind' => 'local',
            'image_analysis' => true,
            'image_embeddings' => true,
            'text_embeddings' => true,
            'same_embedding_space' => true,
            'model_id' => 'stub-openclip',
            'model_version' => 'stub-fixture',
            'model_space' => 'stub-openclip:3:cosine',
            'dimensions' => 3,
            'distance_metric' => 'cosine',
        ], JSON_THROW_ON_ERROR);

        return true;
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (! is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid json'], JSON_THROW_ON_ERROR);

        return true;
    }

    if ($path === '/v1/analyze-image') {
        if (! is_string($body['image_base64'] ?? null) || ! is_string($body['sha256'] ?? null)) {
            http_response_code(422);
            echo json_encode(['error' => 'image_base64 and sha256 required'], JSON_THROW_ON_ERROR);

            return true;
        }
        echo json_encode([
            'description' => 'Contractstub: kleine testafbeelding.',
            'tags' => ['contractstub'],
            'objects' => ['testkaart'],
            'confidence' => 0.5,
        ], JSON_THROW_ON_ERROR);

        return true;
    }

    if ($path === '/v1/embed-image' || $path === '/v1/embed-text') {
        echo json_encode([
            'embedding' => $path === '/v1/embed-image' ? [1.0, 0.0, 0.0] : [0.9, 0.1, 0.0],
            'model_space' => 'stub-openclip:3:cosine',
            'dimensions' => 3,
        ], JSON_THROW_ON_ERROR);

        return true;
    }

    http_response_code(404);
    echo json_encode(['error' => 'not found'], JSON_THROW_ON_ERROR);

    return true;
}

$port = (int) (getenv('FOTOARCHIEF_LOCAL_AI_STUB_PORT') ?: random_int(21000, 29000));
$log = tempnam(sys_get_temp_dir(), 'fotoarchief-local-ai-stub-');
if ($log === false) {
    fwrite(STDERR, "Cannot create local AI stub log file.\n");
    exit(1);
}

$command = escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.' '.escapeshellarg(__FILE__);
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
], $pipes);

if (! is_resource($process)) {
    fwrite(STDERR, "Cannot start local AI contract stub.\n");
    exit(1);
}

try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $capabilities = http_json('GET', "http://127.0.0.1:{$port}/v1/capabilities");
        if (($capabilities['status'] ?? 0) === 200) {
            break;
        }
        usleep(100000);
    }

    $capabilities = http_json('GET', "http://127.0.0.1:{$port}/v1/capabilities");
    assert_contract(($capabilities['json'] ?? null) instanceof stdClass || is_array($capabilities['json'] ?? null), 'Capabilities returned JSON.');
    $capabilityBody = (array) $capabilities['json'];
    assert_contract(($capabilityBody['provider_kind'] ?? null) === 'local', 'provider_kind is local.');
    assert_contract(($capabilityBody['image_embeddings'] ?? null) === true && ($capabilityBody['text_embeddings'] ?? null) === true, 'Image and text embeddings are both advertised.');
    assert_contract(($capabilityBody['same_embedding_space'] ?? null) === true, 'Embeddings share one model space.');

    $imageBytes = "fotoarchief-contract-stub-image\n";
    $analysis = http_json('POST', "http://127.0.0.1:{$port}/v1/analyze-image", [
        'image_base64' => base64_encode($imageBytes),
        'sha256' => hash('sha256', $imageBytes),
        'language' => 'nl',
        'context' => ['probe' => 'local-contract-stub'],
    ]);
    $analysisBody = (array) $analysis['json'];
    assert_contract(($analysis['status'] ?? 0) === 200 && is_string($analysisBody['description'] ?? null) && is_array($analysisBody['tags'] ?? null), 'Image analysis schema is usable.');

    $image = (array) http_json('POST', "http://127.0.0.1:{$port}/v1/embed-image", [
        'image_base64' => base64_encode($imageBytes),
        'sha256' => hash('sha256', $imageBytes),
    ])['json'];
    $text = (array) http_json('POST', "http://127.0.0.1:{$port}/v1/embed-text", [
        'text' => 'dorpsplein',
        'language' => 'nl',
    ])['json'];
    assert_contract(($image['model_space'] ?? null) === ($text['model_space'] ?? null), 'Image and text model spaces match.');
    assert_contract(($image['dimensions'] ?? null) === ($text['dimensions'] ?? null), 'Image and text dimensions match.');
    assert_contract(count((array) ($image['embedding'] ?? [])) === (int) $image['dimensions'], 'Image vector length matches dimensions.');
    assert_contract(count((array) ($text['embedding'] ?? [])) === (int) $text['dimensions'], 'Text vector length matches dimensions.');

    echo "Local AI HTTP contract stub passed on http://127.0.0.1:{$port}.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($log);
}

/**
 * @param  array<string, mixed>|null  $payload
 * @return array{status: int, json: mixed}
 */
function http_json(string $method, string $url, ?array $payload = null): array
{
    $headers = "Accept: application/json\r\n";
    $content = null;
    if ($payload !== null) {
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers .= "Content-Type: application/json\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 2,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return [
        'status' => $status,
        'json' => is_string($body) && $body !== '' ? json_decode($body, false, 512, JSON_THROW_ON_ERROR) : null,
    ];
}

function assert_contract(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "Local AI HTTP contract failed: {$message}\n");
        exit(1);
    }
}
