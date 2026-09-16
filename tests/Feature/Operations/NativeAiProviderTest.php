<?php

declare(strict_types=1);

use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\Native\AnthropicProvider;
use App\Modules\Ai\Services\Native\GeminiProvider;
use App\Modules\Ai\Services\Native\OpenAiProvider;
use App\Modules\Ai\Services\Native\OpenRouterProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Contract tests for the four native provider adapters, exercised entirely
 * against Http::fake() fixtures shaped like each vendor's real, documented
 * response envelope (per first-party docs as of 2026-09-16). No network
 * call ever reaches a real provider in this suite; these confirm the
 * adapters parse the real shapes and map 401/403/429/5xx/timeout/malformed
 * responses to a single, redacted AiProviderException per provider.
 */
function configureNativeProvider(string $provider, array $overrides = []): void
{
    config([
        "ai.native_providers.{$provider}" => array_merge(config("ai.native_providers.{$provider}", []), [
            'api_key' => 'test-key-'.$provider,
            'base_url' => "https://native-{$provider}.test",
        ], $overrides),
    ]);
}

// --- OpenAI --------------------------------------------------------------

it('parses a successful OpenAI vision analysis response', function (): void {
    configureNativeProvider('openai', ['vision_model' => 'gpt-4.1-mini']);
    Http::fake(['https://native-openai.test/chat/completions' => Http::response([
        'model' => 'gpt-4.1-mini-2026-01-01',
        'choices' => [['message' => ['content' => '{"description":"Een dorpsplein met fietsen.","tags":["dorpsplein","fietsen"],"confidence":0.82}']]],
    ])]);

    $result = app(OpenAiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gpt-4.1-mini', 'timeout_seconds' => 10]);

    expect($result['description'])->toBe('Een dorpsplein met fietsen.')
        ->and($result['tags'])->toBe(['dorpsplein', 'fietsen'])
        ->and($result['confidence'])->toBe(0.82)
        ->and($result['model_id'])->toBe('gpt-4.1-mini')
        ->and($result['model_version'])->toBe('gpt-4.1-mini-2026-01-01');
});

it('maps OpenAI 401/403/429/5xx and malformed JSON to redacted AiProviderException', function (int $status, ?string $expected, array $headers = []): void {
    configureNativeProvider('openai', ['vision_model' => 'gpt-4.1-mini']);
    Http::fake(['https://native-openai.test/chat/completions' => Http::response('geheime-header-inhoud', $status, $headers)]);

    expect(fn () => app(OpenAiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gpt-4.1-mini']))
        ->toThrow(AiProviderException::class, $expected);
})->with([
    'unauthorized' => [401, 'ongeldige of ontbrekende API-sleutel'],
    'forbidden' => [403, 'modelrechten'],
    'rate limited' => [429, 'ratelimiet bereikt (429); retry-after 12s', ['Retry-After' => '12']],
    'server error' => [500, 'providerserverfout'],
]);

it('never leaks the raw OpenAI response body into the exception message', function (): void {
    configureNativeProvider('openai', ['vision_model' => 'gpt-4.1-mini']);
    Http::fake(['https://native-openai.test/chat/completions' => Http::response('geheime-header-inhoud', 500)]);

    try {
        app(OpenAiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gpt-4.1-mini']);
        expect(false)->toBeTrue('expected exception');
    } catch (AiProviderException $exception) {
        expect($exception->getMessage())->not->toContain('geheime-header-inhoud');
    }
});

it('rejects a malformed (non-JSON) OpenAI analysis body', function (): void {
    configureNativeProvider('openai', ['vision_model' => 'gpt-4.1-mini']);
    Http::fake(['https://native-openai.test/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'dit is geen json']]],
    ])]);

    expect(fn () => app(OpenAiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gpt-4.1-mini']))
        ->toThrow(AiProviderException::class, 'geen geldige JSON-analyse');
});

it('times out cleanly against OpenAI without an unhandled exception', function (): void {
    configureNativeProvider('openai', ['vision_model' => 'gpt-4.1-mini']);
    Http::fake(fn () => throw new ConnectionException('connect timeout'));

    expect(fn () => app(OpenAiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gpt-4.1-mini']))
        ->toThrow(AiProviderException::class, 'timeout of verbindingsfout');
});

it('refuses to call OpenAI without a configured model', function (): void {
    configureNativeProvider('openai', ['vision_model' => null]);

    expect(fn () => app(OpenAiProvider::class)->analyzeImage('jpeg-bytes', []))
        ->toThrow(AiProviderException::class, 'geen visionmodel geconfigureerd');
});

// --- Anthropic -------------------------------------------------------------

it('parses a successful Anthropic vision analysis response', function (): void {
    configureNativeProvider('anthropic', ['vision_model' => 'claude-sonnet-5']);
    Http::fake(['https://native-anthropic.test/v1/messages' => Http::response([
        'model' => 'claude-sonnet-5-20260101',
        'content' => [['type' => 'text', 'text' => '{"description":"Een groepsfoto.","tags":["groep"],"confidence":0.6}']],
    ])]);

    $result = app(AnthropicProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'claude-sonnet-5']);

    expect($result['description'])->toBe('Een groepsfoto.')
        ->and($result['tags'])->toBe(['groep']);
});

it('maps Anthropic error statuses to a redacted AiProviderException', function (int $status, string $expected): void {
    configureNativeProvider('anthropic', ['vision_model' => 'claude-sonnet-5']);
    Http::fake(['https://native-anthropic.test/v1/messages' => Http::response(['type' => 'error'], $status)]);

    expect(fn () => app(AnthropicProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'claude-sonnet-5']))
        ->toThrow(AiProviderException::class, $expected);
})->with([
    'unauthorized' => [401, 'ongeldige of ontbrekende API-sleutel'],
    'rate limited' => [429, 'ratelimiet bereikt'],
    'server error' => [503, 'providerserverfout'],
]);

// --- Gemini ------------------------------------------------------------

it('parses a successful Gemini vision analysis response', function (): void {
    configureNativeProvider('gemini', ['vision_model' => 'gemini-2.5-flash']);
    Http::fake(['https://native-gemini.test/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"description":"Een molen.","tags":["molen"],"confidence":0.9}']]]]],
    ])]);

    $result = app(GeminiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gemini-2.5-flash']);

    expect($result['description'])->toBe('Een molen.');
});

it('embeds an image into the documented multimodal gemini-embedding-2 shared space', function (): void {
    configureNativeProvider('gemini', ['embedding_model' => 'gemini-embedding-2']);
    Http::fake(['https://native-gemini.test/v1beta/models/gemini-embedding-2:embedContent' => Http::response([
        'embedding' => ['values' => [0.1, 0.2, 0.3]],
    ])]);

    $result = app(GeminiProvider::class)->embedImage('jpeg-bytes', ['model' => 'gemini-embedding-2']);

    expect($result['embedding'])->toBe([0.1, 0.2, 0.3])
        ->and($result['dimensions'])->toBe(3)
        ->and($result['model_space'])->toBe('gemini:gemini-embedding-2:3:cosine');
});

it('embeds text into the same gemini-embedding-2 shared space as images', function (): void {
    configureNativeProvider('gemini', ['embedding_model' => 'gemini-embedding-2']);
    Http::fake(['https://native-gemini.test/v1beta/models/gemini-embedding-2:embedContent' => Http::response([
        'embedding' => ['values' => [0.1, 0.2, 0.3]],
    ])]);

    $result = app(GeminiProvider::class)->embedText('dorpsplein', ['model' => 'gemini-embedding-2']);

    expect($result['model_space'])->toBe('gemini:gemini-embedding-2:3:cosine');
});

it('maps a Gemini rate limit to a redacted AiProviderException with retry-after', function (): void {
    configureNativeProvider('gemini', ['vision_model' => 'gemini-2.5-flash']);
    Http::fake(['https://native-gemini.test/v1beta/models/gemini-2.5-flash:generateContent' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '5'])]);

    expect(fn () => app(GeminiProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'gemini-2.5-flash']))
        ->toThrow(AiProviderException::class, 'retry-after 5s');
});

it('rejects a Gemini embedding response missing embedding.values', function (): void {
    configureNativeProvider('gemini', ['embedding_model' => 'gemini-embedding-2']);
    Http::fake(['https://native-gemini.test/v1beta/models/gemini-embedding-2:embedContent' => Http::response(['embedding' => []])]);

    expect(fn () => app(GeminiProvider::class)->embedImage('jpeg-bytes', ['model' => 'gemini-embedding-2']))
        ->toThrow(AiProviderException::class, 'miste embedding.values');
});

// --- OpenRouter --------------------------------------------------------

it('parses a successful OpenRouter vision analysis response', function (): void {
    configureNativeProvider('openrouter', ['vision_model' => 'openai/gpt-4.1-mini']);
    Http::fake(['https://native-openrouter.test/chat/completions' => Http::response([
        'model' => 'openai/gpt-4.1-mini',
        'choices' => [['message' => ['content' => '{"description":"Een kerktoren.","tags":["kerk"],"confidence":0.5}']]],
    ])]);

    $result = app(OpenRouterProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'openai/gpt-4.1-mini']);

    expect($result['description'])->toBe('Een kerktoren.');
});

it('embeds an image with an allowlisted OpenRouter multimodal model', function (): void {
    configureNativeProvider('openrouter', [
        'embedding_model' => 'nvidia/llama-nemotron-embed-vl-1b-v2',
        'embedding_model_allowlist' => ['nvidia/llama-nemotron-embed-vl-1b-v2'],
    ]);
    Http::fake(['https://native-openrouter.test/embeddings' => Http::response([
        'data' => [['embedding' => [0.4, 0.5]]],
    ])]);

    $result = app(OpenRouterProvider::class)->embedImage('jpeg-bytes', ['model' => 'nvidia/llama-nemotron-embed-vl-1b-v2']);

    expect($result['dimensions'])->toBe(2);
});

it('refuses an OpenRouter embedding model that is not on the multimodal allowlist', function (): void {
    configureNativeProvider('openrouter', [
        'embedding_model' => 'text-only/some-model',
        'embedding_model_allowlist' => ['nvidia/llama-nemotron-embed-vl-1b-v2'],
    ]);

    expect(fn () => app(OpenRouterProvider::class)->embedImage('jpeg-bytes', ['model' => 'text-only/some-model']))
        ->toThrow(AiProviderException::class, 'staat niet op de toegestane multimodale modellenlijst');
});

it('maps OpenRouter error statuses to a redacted AiProviderException', function (int $status, string $expected): void {
    configureNativeProvider('openrouter', ['vision_model' => 'openai/gpt-4.1-mini']);
    Http::fake(['https://native-openrouter.test/chat/completions' => Http::response(['error' => 'nope'], $status)]);

    expect(fn () => app(OpenRouterProvider::class)->analyzeImage('jpeg-bytes', ['model' => 'openai/gpt-4.1-mini']))
        ->toThrow(AiProviderException::class, $expected);
})->with([
    'unauthorized' => [401, 'ongeldige of ontbrekende API-sleutel'],
    'server error' => [502, 'providerserverfout'],
]);

// --- probe() connection test (cheap, non-billable model listing) -------

it('lists visible OpenAI models via the cheap probe endpoint', function (): void {
    configureNativeProvider('openai');
    Http::fake(['https://native-openai.test/models' => Http::response([
        'data' => [['id' => 'gpt-4.1-mini'], ['id' => 'gpt-4.1']],
    ])]);

    expect(app(OpenAiProvider::class)->probe())->toBe(['models' => ['gpt-4.1-mini', 'gpt-4.1']]);
});

it('maps an OpenAI probe 401 to a redacted AiProviderException', function (): void {
    configureNativeProvider('openai');
    Http::fake(['https://native-openai.test/models' => Http::response('geheim', 401)]);

    expect(fn () => app(OpenAiProvider::class)->probe())
        ->toThrow(AiProviderException::class, 'ongeldige of ontbrekende API-sleutel');
});

it('lists visible Anthropic models via the cheap probe endpoint', function (): void {
    configureNativeProvider('anthropic');
    Http::fake(['https://native-anthropic.test/v1/models' => Http::response([
        'data' => [['id' => 'claude-sonnet-5'], ['id' => 'claude-opus-5']],
    ])]);

    expect(app(AnthropicProvider::class)->probe())->toBe(['models' => ['claude-sonnet-5', 'claude-opus-5']]);
});

it('lists visible Gemini models via the cheap probe endpoint, stripping the models/ prefix', function (): void {
    configureNativeProvider('gemini');
    Http::fake(['https://native-gemini.test/v1beta/models' => Http::response([
        'models' => [['name' => 'models/gemini-embedding-2'], ['name' => 'models/gemini-2.5-flash']],
    ])]);

    expect(app(GeminiProvider::class)->probe())->toBe(['models' => ['gemini-embedding-2', 'gemini-2.5-flash']]);
});

it('maps a Gemini probe 403 to a redacted AiProviderException', function (): void {
    configureNativeProvider('gemini');
    Http::fake(['https://native-gemini.test/v1beta/models' => Http::response('geheim', 403)]);

    expect(fn () => app(GeminiProvider::class)->probe())
        ->toThrow(AiProviderException::class, 'modelrechten');
});

it('lists visible OpenRouter models via the cheap probe endpoint', function (): void {
    configureNativeProvider('openrouter');
    Http::fake(['https://native-openrouter.test/models' => Http::response([
        'data' => [['id' => 'google/gemini-embedding-2']],
    ])]);

    expect(app(OpenRouterProvider::class)->probe())->toBe(['models' => ['google/gemini-embedding-2']]);
});

it('maps an OpenRouter probe 429 with retry-after to a redacted AiProviderException', function (): void {
    configureNativeProvider('openrouter');
    Http::fake(['https://native-openrouter.test/models' => Http::response('geheim', 429, ['Retry-After' => '5'])]);

    expect(fn () => app(OpenRouterProvider::class)->probe())
        ->toThrow(AiProviderException::class, 'ratelimiet bereikt (429); retry-after 5s');
});
