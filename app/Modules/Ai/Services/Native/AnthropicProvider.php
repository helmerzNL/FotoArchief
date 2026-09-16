<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services\Native;

use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

/**
 * Native Anthropic (Claude) adapter. Image analysis only: Anthropic offers
 * no native embeddings API and its own docs defer embeddings entirely to a
 * separate vendor (Voyage AI), which is out of scope for this integration.
 *
 * https://docs.anthropic.com/en/docs/build-with-claude/vision (messages API, image content block)
 */
class AnthropicProvider extends AbstractNativeProvider implements ImageAnalysisProvider
{
    protected function providerKey(): string
    {
        return 'anthropic';
    }

    protected function providerLabel(): string
    {
        return 'Anthropic';
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
            throw new AiProviderException('Anthropic: geen visionmodel geconfigureerd.');
        }

        $request = $this->http->withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => (string) ($this->nativeConfig()['api_version'] ?? '2023-06-01'),
        ]);
        $payload = $this->postJson($request, $this->baseUrl().'/v1/messages', [
            'model' => $model,
            'max_tokens' => 500,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::ANALYSIS_PROMPT],
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($imageBytes)]],
                ],
            ]],
        ], $this->timeoutSeconds($context));

        $text = Arr::get($payload, 'content.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('Anthropic: geen tekstinhoud in het antwoord.');
        }

        $analysis = $this->parseAnalysisJson($text);
        $analysis['model_id'] = $model;
        $analysis['model_version'] = is_string(Arr::get($payload, 'model')) ? Arr::get($payload, 'model') : null;

        return $analysis;
    }

    protected function probeUrl(): string
    {
        return $this->baseUrl().'/v1/models';
    }

    protected function probeRequest(): PendingRequest
    {
        return $this->http->withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => (string) ($this->nativeConfig()['api_version'] ?? '2023-06-01'),
        ]);
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
