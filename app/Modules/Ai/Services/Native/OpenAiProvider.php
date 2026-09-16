<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services\Native;

use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

/**
 * Native OpenAI adapter. Image analysis only: OpenAI's embeddings API
 * (text-embedding-3-*) is explicitly text-only and must never be presented
 * to the app as an image embedding, so this class does not implement
 * EmbeddingProvider.
 *
 * https://platform.openai.com/docs/guides/vision (chat/completions, image_url content part)
 */
class OpenAiProvider extends AbstractNativeProvider implements ImageAnalysisProvider
{
    protected function providerKey(): string
    {
        return 'openai';
    }

    protected function providerLabel(): string
    {
        return 'OpenAI';
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
            throw new AiProviderException('OpenAI: geen visionmodel geconfigureerd.');
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
            throw new AiProviderException('OpenAI: geen tekstinhoud in het antwoord.');
        }

        $analysis = $this->parseAnalysisJson($text);
        $analysis['model_id'] = $model;
        $analysis['model_version'] = is_string(Arr::get($payload, 'model')) ? Arr::get($payload, 'model') : null;

        return $analysis;
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
}
