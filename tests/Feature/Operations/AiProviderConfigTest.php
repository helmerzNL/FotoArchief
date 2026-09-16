<?php

declare(strict_types=1);

use App\Modules\Ai\Models\AiProviderConfig;
use App\Modules\Ai\Services\AiProviderConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores provider keys encrypted and excludes them from model serialization and status', function (): void {
    $service = app(AiProviderConfigService::class);
    $service->setApiKey('openai', 'super-secret-key');

    $row = AiProviderConfig::query()->where('provider', 'openai')->firstOrFail();

    expect($row->getRawOriginal('api_key'))->not->toBe('super-secret-key')
        ->and($row->toArray())->not->toHaveKey('api_key')
        ->and($service->status('openai'))->not->toHaveKey('api_key')
        ->and($service->status('openai')['has_api_key'])->toBeTrue();
});

it('updates provider models costs and budgets while keeping fixed endpoint data', function (): void {
    $service = app(AiProviderConfigService::class);
    $service->update('gemini', [
        'enabled' => true,
        'vision_model' => 'vision-custom',
        'embedding_model' => 'gemini-embedding-2',
        'cost_cents_per_image' => 7,
        'cost_cents_per_embedding' => 3,
        'monthly_budget_cents' => 900,
    ]);

    $status = $service->status('gemini');

    expect($status['enabled'])->toBeTrue()
        ->and($status['vision_model'])->toBe('vision-custom')
        ->and($status['cost_cents_per_image'])->toBe(7)
        ->and($status['monthly_budget_cents'])->toBe(900)
        ->and($status['base_url'])->toBe('https://generativelanguage.googleapis.com');
});

it('rejects an OpenRouter embedding model outside the fixed multimodal allowlist', function (): void {
    expect(fn () => app(AiProviderConfigService::class)->update('openrouter', [
        'embedding_model' => 'text-only-model',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
