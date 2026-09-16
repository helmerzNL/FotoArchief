<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services\Native;

use App\Modules\Ai\Contracts\ConnectionProbe;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\AiProviderConfigService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Shared HTTP plumbing for the four native (direct, no-gateway) provider
 * adapters. Every subclass talks to a fixed, first-party base URL from
 * config/ai.php (never an admin-editable endpoint, unlike the custom
 * Local/External protocol) so native providers cannot be pointed at an
 * arbitrary host from the settings UI.
 *
 * Error mapping is centralised here so 401/403/429/5xx/timeouts always
 * produce the same redacted AiProviderException shape (never the raw
 * response body, which could echo request headers back) across providers.
 */
abstract class AbstractNativeProvider implements ConnectionProbe
{
    public function __construct(
        protected readonly HttpFactory $http,
        private readonly AiProviderConfigService $providerConfigs,
    ) {}

    abstract protected function providerLabel(): string;

    /**
     * @return array<string, mixed>
     */
    protected function nativeConfig(): array
    {
        return $this->providerConfigs->runtime($this->providerKey());
    }

    abstract protected function providerKey(): string;

    public static function officialBaseUrl(string $provider): string
    {
        return match ($provider) {
            'openai' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com',
            'gemini' => 'https://generativelanguage.googleapis.com',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => throw new \InvalidArgumentException("Onbekende native AI-provider: {$provider}."),
        };
    }

    public static function officialApiVersion(string $provider): ?string
    {
        return $provider === 'anthropic' ? '2023-06-01' : null;
    }

    /** @return list<string> */
    public static function embeddingModelAllowlist(string $provider): array
    {
        return $provider === 'openrouter'
            ? ['nvidia/llama-nemotron-embed-vl-1b-v2']
            : [];
    }

    protected function apiKey(): string
    {
        $key = (string) ($this->nativeConfig()['api_key'] ?? '');
        if ($key === '') {
            throw new AiProviderException($this->providerLabel().': geen API-sleutel geconfigureerd in de private omgeving.');
        }

        return $key;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->nativeConfig()['base_url'] ?? ''), '/');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function timeoutSeconds(array $context): int
    {
        $timeout = (int) ($context['timeout_seconds'] ?? 60);

        return max(5, min(60, $timeout));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postJson(PendingRequest $request, string $url, array $body, int $timeoutSeconds): array
    {
        try {
            $response = $request->timeout($timeoutSeconds)->acceptJson()->asJson()->post($url, $body);
        } catch (ConnectionException) {
            throw new AiProviderException($this->providerLabel().': verzoek verliep (timeout of verbindingsfout).');
        }

        $this->assertSuccessful($response);

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AiProviderException($this->providerLabel().': antwoord was geen geldige JSON.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJson(PendingRequest $request, string $url): array
    {
        try {
            $response = $request->timeout(15)->acceptJson()->get($url);
        } catch (ConnectionException) {
            throw new AiProviderException($this->providerLabel().': verzoek verliep (timeout of verbindingsfout).');
        }

        $this->assertSuccessful($response);

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AiProviderException($this->providerLabel().': antwoord was geen geldige JSON.');
        }

        return $payload;
    }

    /**
     * Cheap, non-billable connectivity/capability check: lists the models
     * visible to the configured API key. Never performs analyzeImage() or
     * embedText()/embedImage(); those cost real provider tokens and are
     * only ever invoked from the actual analysis/index job pipeline (or,
     * for the "paid image proof" connection test, behind its own explicit
     * one-off operator consent).
     *
     * @return array{models: list<string>}
     */
    public function probe(): array
    {
        $payload = $this->getJson($this->probeRequest(), $this->probeUrl());

        return ['models' => $this->extractModelIds($payload)];
    }

    abstract protected function probeUrl(): string;

    abstract protected function probeRequest(): PendingRequest;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    abstract protected function extractModelIds(array $payload): array;

    protected function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $label = $this->providerLabel();
        $message = match (true) {
            $status === 401 => "{$label}: ongeldige of ontbrekende API-sleutel (401).",
            $status === 403 => "{$label}: toegang geweigerd, controleer modelrechten (403).",
            $status === 429 => "{$label}: ratelimiet bereikt (429)".$this->retryAfterSuffix($response),
            $status >= 500 => "{$label}: providerserverfout ({$status}).",
            default => "{$label}: verzoek mislukt met status {$status}.",
        };

        throw new AiProviderException($message);
    }

    private function retryAfterSuffix(Response $response): string
    {
        $retryAfter = $response->header('Retry-After');

        return $retryAfter !== '' && $retryAfter !== null ? "; retry-after {$retryAfter}s" : '';
    }

    /**
     * Parses a fenced-or-plain JSON analysis blob into a normalized
     * description/tags/confidence array. Every native provider is prompted
     * to answer with strict JSON; a provider that fails to do so is treated
     * as a malformed response rather than silently guessed at.
     *
     * @return array{description: string, tags: list<string>, confidence: float|null}
     */
    protected function parseAnalysisJson(string $text): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```\s*$/', '', trim($clean)) ?? $clean;
        $decoded = json_decode(trim($clean), true);

        if (! is_array($decoded) || ! is_string($decoded['description'] ?? null) || trim($decoded['description']) === '' || ! is_array($decoded['tags'] ?? null)) {
            throw new AiProviderException($this->providerLabel().': leverde geen geldige JSON-analyse (description/tags verwacht).');
        }

        return [
            'description' => $decoded['description'],
            'tags' => array_values(array_filter($decoded['tags'], 'is_string')),
            'confidence' => is_numeric($decoded['confidence'] ?? null) ? (float) $decoded['confidence'] : null,
        ];
    }

    protected const string ANALYSIS_PROMPT = <<<'PROMPT'
        Beschrijf deze afbeelding voor een archiefcatalogus in het Nederlands.
        Antwoord uitsluitend met geldig JSON zonder toelichting, in dit exacte formaat:
        {"description": "korte neutrale beschrijving", "tags": ["max", "20", "losse", "trefwoorden"], "confidence": 0.0}
        Beschrijf alleen wat zichtbaar is. Benoem geen personen bij naam, doe geen gezichtsherkenning en geef geen gevoelige persoonsinferenties (gezondheid, etniciteit, geaardheid, religie, politieke overtuiging).
        PROMPT;
}
