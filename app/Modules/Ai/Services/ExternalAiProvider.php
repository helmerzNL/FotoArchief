<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\ConnectionProbe;
use App\Modules\Ai\Contracts\EmbeddingProvider;
use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

class ExternalAiProvider implements ConnectionProbe, EmbeddingProvider, ImageAnalysisProvider
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly AiProviderConfigService $providerConfigs,
        private readonly HttpFactory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        $settings = $this->externalSettings();
        $response = $this->request($settings)->get(rtrim((string) $settings['external_endpoint'], '/').'/v1/capabilities');

        if (! $response->successful()) {
            throw new AiProviderException('External AI capability probe failed with status '.$response->status().'.');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AiProviderException('External AI capability probe returned invalid JSON.');
        }
        $this->assertCapabilities($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function analyzeImage(string $imageBytes, array $context): array
    {
        $settings = $this->externalSettings();
        $payload = $this->postJson($settings, '/v1/analyze-image', [
            'image_base64' => base64_encode($imageBytes),
            'sha256' => hash('sha256', $imageBytes),
            'language' => 'nl',
            'context' => $context,
            'privacy' => [
                'provider_region' => $settings['provider_region'],
                'retention_notice' => $settings['retention_notice'],
                'external_budget_cents' => $settings['monthly_external_budget_cents'],
            ],
        ]);

        if (! is_string(Arr::get($payload, 'description')) || ! is_array(Arr::get($payload, 'tags'))) {
            throw new AiProviderException('External AI image analysis response misses description or tags.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedImage(string $imageBytes, array $context = []): array
    {
        $settings = $this->externalSettings();
        $payload = $this->postJson($settings, '/v1/embed-image', [
            'image_base64' => base64_encode($imageBytes),
            'sha256' => hash('sha256', $imageBytes),
        ]);

        return $this->embeddingPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedText(string $query, array $context = []): array
    {
        $settings = $this->externalSettings();
        $payload = $this->postJson($settings, '/v1/embed-text', [
            'text' => $query,
            'language' => 'nl',
        ]);

        return $this->embeddingPayload($payload);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function externalSettings(): array
    {
        $settings = $this->configuration->effective();
        if (! (bool) ($settings['external_ready'] ?? false)) {
            throw new AiProviderException('External AI provider is not explicitly enabled, consented and budgeted.');
        }

        return array_merge($settings, $this->providerConfigs->runtime('external'));
    }

    /**
     * @param  array<string, bool|int|string|null>  $settings
     */
    private function request(array $settings): PendingRequest
    {
        $request = $this->http->timeout((int) $settings['request_timeout_seconds'])
            ->acceptJson()
            ->asJson();
        $apiKey = (string) ($settings['api_key'] ?? '');
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        return $request;
    }

    /**
     * @param  array<string, bool|int|string|null>  $settings
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(array $settings, string $path, array $body): array
    {
        $response = $this->request($settings)->post(rtrim((string) $settings['external_endpoint'], '/').$path, $body);

        if (! $response->successful()) {
            throw new AiProviderException("External AI request {$path} failed with status ".$response->status().'.');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AiProviderException("External AI request {$path} returned invalid JSON.");
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertCapabilities(array $payload): void
    {
        if (Arr::get($payload, 'provider_kind') !== 'external') {
            throw new AiProviderException('External AI provider must report provider_kind=external.');
        }
        if (Arr::get($payload, 'image_analysis') !== true || Arr::get($payload, 'image_embeddings') !== true || Arr::get($payload, 'text_embeddings') !== true) {
            throw new AiProviderException('External AI provider must report image analysis plus text/image embeddings.');
        }
        if (Arr::get($payload, 'same_embedding_space') !== true) {
            throw new AiProviderException('External AI provider must prove text and image embeddings share one model space.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    private function embeddingPayload(array $payload): array
    {
        $embedding = Arr::get($payload, 'embedding');
        $modelSpace = Arr::get($payload, 'model_space');
        $dimensions = Arr::get($payload, 'dimensions');
        if (! is_array($embedding) || $embedding === [] || ! is_string($modelSpace) || ! is_numeric($dimensions)) {
            throw new AiProviderException('External AI embedding response misses embedding, model_space or dimensions.');
        }
        if (count($embedding) !== (int) $dimensions) {
            throw new AiProviderException('External AI embedding dimensions do not match the vector length.');
        }

        return [
            'embedding' => array_values($embedding),
            'model_space' => $modelSpace,
            'dimensions' => (int) $dimensions,
        ];
    }
}
