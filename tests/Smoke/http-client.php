<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function request(string $method, string $path, ?array $data = null, bool $json = false): array
{
    global $base, $cookie;
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [$json ? 'Accept: application/json' : 'Accept: text/html'],
    ]);
    if ($data !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    check(is_string($body), 'HTTP transport failed.');

    return [$status, $body];
}

function token(string $html): string
{
    check(preg_match('/name="_token"\s+value="([^"]+)"/', $html, $matches) === 1, 'CSRF form token missing.');

    return html_entity_decode($matches[1], ENT_QUOTES);
}
