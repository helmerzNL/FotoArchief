<?php

declare(strict_types=1);

if ($argc !== 3 || $argv[2] !== '--confirm-send-test-image') {
    fwrite(STDERR, "Usage: php scripts/ai-local-contract-probe.php https://local-ai.example.invalid --confirm-send-test-image\n");
    fwrite(STDERR, "Runs a contract probe only. It is not model-quality proof and does not print image bytes, embeddings or secrets.\n");
    exit(2);
}

$baseUrl = rtrim($argv[1], '/');
if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
    fwrite(STDERR, "Endpoint must be an absolute HTTP(S) URL.\n");
    exit(2);
}

$imageBytes = "fotoarchief-local-contract-probe\n";
$capabilities = request_json('GET', $baseUrl.'/v1/capabilities');
$imageEmbedding = request_json('POST', $baseUrl.'/v1/embed-image', [
    'image_base64' => base64_encode($imageBytes),
    'sha256' => hash('sha256', $imageBytes),
]);
$textEmbedding = request_json('POST', $baseUrl.'/v1/embed-text', [
    'text' => 'dorpsplein',
    'language' => 'nl',
]);

if (($capabilities['provider_kind'] ?? null) !== 'local'
    || ($capabilities['image_embeddings'] ?? null) !== true
    || ($capabilities['text_embeddings'] ?? null) !== true
    || ($capabilities['same_embedding_space'] ?? null) !== true) {
    fwrite(STDERR, "Local AI probe failed: capability response does not prove local shared text/image embeddings.\n");
    exit(1);
}

if (($imageEmbedding['model_space'] ?? null) !== ($textEmbedding['model_space'] ?? null)
    || ($imageEmbedding['dimensions'] ?? null) !== ($textEmbedding['dimensions'] ?? null)) {
    fwrite(STDERR, "Local AI probe failed: image and text embeddings do not share model space and dimensions.\n");
    exit(1);
}

$report = [
    'endpoint' => redact_url($baseUrl),
    'provider_kind' => $capabilities['provider_kind'],
    'model_id' => $capabilities['model_id'] ?? null,
    'model_version' => $capabilities['model_version'] ?? null,
    'model_space' => $imageEmbedding['model_space'],
    'dimensions' => $imageEmbedding['dimensions'],
    'distance_metric' => $capabilities['distance_metric'] ?? 'cosine',
    'same_embedding_space' => true,
    'contract_only' => true,
    'model_execution_quality_proven' => false,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

/**
 * @param  array<string, mixed>|null  $payload
 * @return array<string, mixed>
 */
function request_json(string $method, string $url, ?array $payload = null): array
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
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    if ($status < 200 || $status >= 300 || ! is_string($body)) {
        fwrite(STDERR, "Local AI probe request failed: {$method} {$url} returned HTTP {$status}.\n");
        exit(1);
    }
    $decoded = json_decode($body, true);
    if (! is_array($decoded)) {
        fwrite(STDERR, "Local AI probe request failed: {$method} {$url} did not return JSON object.\n");
        exit(1);
    }

    return $decoded;
}

function redact_url(string $url): string
{
    $parts = parse_url($url);
    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return $url;
    }

    return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
}
