<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services\Native;

use App\Modules\Ai\Contracts\EmbeddingProvider;
use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

/**
 * Native Google Gemini adapter.
 *
 * Image analysis: generateContent with gemini-2.5-flash (or a configured
 * override), https://ai.google.dev/gemini-api/docs/image-understanding.
 *
 * Embeddings: embedContent with gemini-embedding-2, which Google documents
 * as a genuinely multimodal model producing text/image/video/audio vectors
 * in one shared space (https://ai.google.dev/gemini-api/docs/embeddings).
 * gemini-embedding-001 remains text-only and must not be selected for image
 * embeddings; the config default therefore points at gemini-embedding-2.
 */
class GeminiProvider extends AbstractNativeProvider implements EmbeddingProvider, ImageAnalysisProvider
{
    protected function providerKey(): string
    {
        return 'gemini';
    }

    protected function providerLabel(): string
    {
        return 'Gemini';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function analyzeImage(string $imageBytes, array $context): array
    {
        $model = is_string($context['model'] ?? null) && $context['model'] !== ''
            ? $context['model']
            : (string) ($this->nativeConfig()['vision_model'] ?? '');
        if ($model === '') {
            throw new AiProviderException('Gemini: geen visionmodel geconfigureerd.');
        }

        $request = $this->http->withHeaders(['x-goog-api-key' => $this->apiKey()]);
        $payload = $this->postJson($request, "{$this->baseUrl()}/v1beta/models/{$model}:generateContent", [
            'contents' => [[
                'parts' => [
                    ['text' => self::ANALYSIS_PROMPT],
                    ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($imageBytes)]],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ], $this->timeoutSeconds($context));

        $text = Arr::get($payload, 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('Gemini: geen tekstinhoud in het antwoord.');
        }

        $analysis = $this->parseAnalysisJson($text);
        $analysis['model_id'] = $model;
        $analysis['model_version'] = $model;

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedImage(string $imageBytes, array $context = []): array
    {
        return $this->embed($context, [
            ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($imageBytes)]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedText(string $query, array $context = []): array
    {
        return $this->embed($context, [['text' => $query]]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $parts
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    private function embed(array $context, array $parts): array
    {
        $model = is_string($context['model'] ?? null) && $context['model'] !== ''
            ? $context['model']
            : (string) ($this->nativeConfig()['embedding_model'] ?? '');
        if ($model === '') {
            throw new AiProviderException('Gemini: geen embeddingmodel geconfigureerd.');
        }

        $request = $this->http->withHeaders(['x-goog-api-key' => $this->apiKey()]);
        $payload = $this->postJson($request, "{$this->baseUrl()}/v1beta/models/{$model}:embedContent", [
            'content' => ['parts' => $parts],
        ], $this->timeoutSeconds($context));

        $values = Arr::get($payload, 'embedding.values');
        if (! is_array($values) || $values === []) {
            throw new AiProviderException('Gemini: antwoord miste embedding.values.');
        }

        $embedding = array_values(array_map(static fn (mixed $v): float => (float) $v, $values));

        return [
            'embedding' => $embedding,
            'model_space' => "gemini:{$model}:".count($embedding).':cosine',
            'dimensions' => count($embedding),
        ];
    }

    protected function probeUrl(): string
    {
        return "{$this->baseUrl()}/v1beta/models";
    }

    protected function probeRequest(): PendingRequest
    {
        return $this->http->withHeaders(['x-goog-api-key' => $this->apiKey()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function extractModelIds(array $payload): array
    {
        $data = Arr::get($payload, 'models');
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $entry): ?string {
                $name = is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : null;

                return $name !== null ? preg_replace('#^models/#', '', $name) : null;
            },
            $data,
        )));
    }
}
