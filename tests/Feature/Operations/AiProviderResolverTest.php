<?php

declare(strict_types=1);

use App\Modules\Ai\Contracts\EmbeddingProvider;
use App\Modules\Ai\Contracts\ImageAnalysisProvider;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\AiProviderResolver;
use App\Modules\Ai\Services\ExternalAiProvider;
use App\Modules\Ai\Services\LocalAiProvider;
use App\Modules\Ai\Services\Native\AnthropicProvider;
use App\Modules\Ai\Services\Native\GeminiProvider;
use App\Modules\Ai\Services\Native\OpenAiProvider;
use App\Modules\Ai\Services\Native\OpenRouterProvider;

it('resolves each supported provider key to the matching image-analysis adapter', function (string $key, string $class): void {
    expect(app(AiProviderResolver::class)->resolveImageAnalysis($key))->toBeInstanceOf($class);
})->with([
    'local' => ['local', LocalAiProvider::class],
    'external' => ['external', ExternalAiProvider::class],
    'openai' => ['openai', OpenAiProvider::class],
    'anthropic' => ['anthropic', AnthropicProvider::class],
    'gemini' => ['gemini', GeminiProvider::class],
    'openrouter' => ['openrouter', OpenRouterProvider::class],
]);

it('resolves each embeddings-capable provider key to the matching adapter', function (string $key, string $class): void {
    expect(app(AiProviderResolver::class)->resolveEmbeddings($key))->toBeInstanceOf($class);
})->with([
    'local' => ['local', LocalAiProvider::class],
    'external' => ['external', ExternalAiProvider::class],
    'gemini' => ['gemini', GeminiProvider::class],
    'openrouter' => ['openrouter', OpenRouterProvider::class],
]);

it('never silently falls back to another provider for an unknown key', function (): void {
    expect(fn () => app(AiProviderResolver::class)->resolveImageAnalysis('does-not-exist'))
        ->toThrow(AiProviderException::class, 'Onbekende AI-provider');
});

it('refuses to resolve embeddings for a provider that does not support them', function (): void {
    expect(fn () => app(AiProviderResolver::class)->resolveEmbeddings('openai'))
        ->toThrow(AiProviderException::class, 'ondersteunt geen embeddings');

    expect(fn () => app(AiProviderResolver::class)->resolveEmbeddings('anthropic'))
        ->toThrow(AiProviderException::class, 'ondersteunt geen embeddings');
});

it('returns interface-typed instances so callers never need provider-specific branching', function (): void {
    expect(app(AiProviderResolver::class)->resolveImageAnalysis('gemini'))->toBeInstanceOf(ImageAnalysisProvider::class)
        ->and(app(AiProviderResolver::class)->resolveEmbeddings('gemini'))->toBeInstanceOf(EmbeddingProvider::class);
});
