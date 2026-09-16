<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\ConnectionProbe;
use App\Modules\Ai\Contracts\EmbeddingProvider;
use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\Native\AnthropicProvider;
use App\Modules\Ai\Services\Native\GeminiProvider;
use App\Modules\Ai\Services\Native\OpenAiProvider;
use App\Modules\Ai\Services\Native\OpenRouterProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Central capability -> provider resolver. This is the single place that
 * turns "which provider is configured for image analysis / embeddings" into
 * a concrete adapter instance, so jobs and services never hardcode a
 * local/external if/else again. Never performs cross-provider failover: a
 * capability resolves to exactly one configured provider or throws.
 */
class AiProviderResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolveImageAnalysis(string $provider): ImageAnalysisProvider
    {
        $instance = $this->instantiate($provider);
        if (! $instance instanceof ImageAnalysisProvider) {
            throw new AiProviderException("Provider {$provider} ondersteunt geen beeldanalyse.");
        }

        return $instance;
    }

    public function resolveEmbeddings(string $provider): EmbeddingProvider
    {
        $instance = $this->instantiate($provider);
        if (! $instance instanceof EmbeddingProvider) {
            throw new AiProviderException("Provider {$provider} ondersteunt geen embeddings.");
        }

        return $instance;
    }

    public function resolveConnectionProbe(string $provider): ConnectionProbe
    {
        $instance = $this->instantiate($provider);
        if (! $instance instanceof ConnectionProbe) {
            throw new AiProviderException("Provider {$provider} ondersteunt geen verbindingstest.");
        }

        return $instance;
    }

    private function instantiate(string $provider): object
    {
        return match ($provider) {
            'local' => $this->container->make(LocalAiProvider::class),
            'external' => $this->container->make(ExternalAiProvider::class),
            'openai' => $this->container->make(OpenAiProvider::class),
            'anthropic' => $this->container->make(AnthropicProvider::class),
            'gemini' => $this->container->make(GeminiProvider::class),
            'openrouter' => $this->container->make(OpenRouterProvider::class),
            default => throw new AiProviderException("Onbekende AI-provider: {$provider}."),
        };
    }
}
