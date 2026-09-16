<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services\Native;

use App\Modules\Ai\Contracts\EmbeddingProvider;
use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

/**
 * Native OpenRouter adapter (OpenAI-compatible routing to whichever upstream
 * model the operator configures).
 *
 * Image analysis: POST /chat/completions with an image_url content part,
 * routed to any vision-capable upstream model.
 *
 * Embeddings: POST /embeddings is OpenAI-compatible for text, but image
 * input is documented as supported only on specific models
 * (https://openrouter.ai/docs/api-reference/embeddings). The embedding
 * model must be present in the configured allowlist for both text and
 * image calls, so a stray text-only model can never be silently used for
 * an image embedding or mixed into the shared vector space.
 *
 * OpenRouter is a routing layer, not a single provider: the upstream model
 * it forwards to has its own privacy/retention policy that this app cannot
 * verify from here, so routing/upstream disclosure lives in the settings UI
 * copy, not in code.
 */
class OpenRouterProvider extends AbstractNativeProvider implements EmbeddingProvider, ImageAnalysisProvider
{
    protected function providerKey(): string
    {
        return 'openrouter';
    }

    protected function providerLabel(): string
    {
        return 'OpenRouter';
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
            throw new AiProviderException('OpenRouter: geen visionmodel geconfigureerd.');
        }

        $request = $this->http->withToken($this->apiKey());
        $payload = $this->postJson($request, $this->baseUrl().'/chat/completions', [
            'model' => $model,
            'max_tokens' => 500,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::ANALYSIS_PROMPT],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($imageBytes)]],
                ],
            ]],
        ], $this->timeoutSeconds($context));

        $text = Arr::get($payload, 'choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('OpenRouter: geen tekstinhoud in het antwoord.');
        }

        $analysis = $this->parseAnalysisJson($text);
        $analysis['model_id'] = $model;
        $analysis['model_version'] = is_string(Arr::get($payload, 'model')) ? Arr::get($payload, 'model') : $model;

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedImage(string $imageBytes, array $context = []): array
    {
        $model = $this->embeddingModel($context);

        return $this->embed($model, [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($imageBytes)]],
        ], $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedText(string $query, array $context = []): array
    {
        $model = $this->embeddingModel($context);

        return $this->embed($model, $query, $context);
    }

    protected function probeUrl(): string
    {
        return $this->baseUrl().'/models';
    }

    protected function probeRequest(): PendingRequest
    {
        return $this->http->withToken($this->apiKey());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function extractModelIds(array $payload): array
    {
        $data = Arr::get($payload, 'data');
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $entry): ?string => is_array($entry) && is_string($entry['id'] ?? null) ? $entry['id'] : null,
            $data,
        )));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function embeddingModel(array $context): string
    {
        $model = is_string($context['model'] ?? null) && $context['model'] !== ''
            ? $context['model']
            : (string) ($this->nativeConfig()['embedding_model'] ?? '');
        if ($model === '') {
            throw new AiProviderException('OpenRouter: geen embeddingmodel geconfigureerd.');
        }

        /** @var list<string> $allowlist */
        $allowlist = Arr::get($this->nativeConfig(), 'embedding_model_allowlist', []);
        if (! in_array($model, $allowlist, true)) {
            throw new AiProviderException("OpenRouter: embeddingmodel {$model} staat niet op de toegestane multimodale modellenlijst.");
        }

        return $model;
    }

    /**
     * @param  string|list<array<string, mixed>>  $input
     * @param  array<string, mixed>  $context
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    private function embed(string $model, string|array $input, array $context): array
    {
        $request = $this->http->withToken($this->apiKey());
        $payload = $this->postJson($request, $this->baseUrl().'/embeddings', [
            'model' => $model,
            'input' => is_string($input) ? $input : [['content' => $input]],
        ], $this->timeoutSeconds($context));

        $values = Arr::get($payload, 'data.0.embedding');
        if (! is_array($values) || $values === []) {
            throw new AiProviderException('OpenRouter: antwoord miste data.0.embedding.');
        }

        $embedding = array_values(array_map(static fn (mixed $v): float => (float) $v, $values));

        return [
            'embedding' => $embedding,
            'model_space' => "openrouter:{$model}:".count($embedding).':cosine',
            'dimensions' => count($embedding),
        ];
    }
}
