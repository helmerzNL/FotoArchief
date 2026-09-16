<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ai\Models\AiProviderConfig;
use App\Modules\Ai\Services\AiProviderConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
    ]))->toThrow(ValidationException::class);
});

it('records actor and changed field type without secret values', function (): void {
    $user = User::query()->create([
        'name' => 'Provider Config Auditor',
        'email' => 'provider-auditor@example.test',
        'password' => bcrypt('test-password'),
    ]);
    $service = app(AiProviderConfigService::class);

    $service->setApiKey('external', 'never-audit-this-secret', $user);

    $audit = DB::table('ai_provider_config_audits')->latest('id')->first();
    expect($audit->user_id)->toBe($user->id)
        ->and($audit->provider)->toBe('external')
        ->and($audit->action)->toBe('api_key_set')
        ->and($audit->changed_fields)->toContain('api_key')
        ->and($audit->changed_fields)->not->toContain('never-audit-this-secret');
});

it('rejects private and local external provider endpoints', function (string $endpoint): void {
    expect(fn () => app(AiProviderConfigService::class)->update('external', [
        'enabled' => true,
        'endpoint' => $endpoint,
        'provider_region' => 'EU',
        'retention_notice' => 'No training.',
    ]))->toThrow(ValidationException::class);
})->with([
    'localhost' => 'https://localhost/v1',
    'localhost subdomain' => 'https://ai.localhost/v1',
    'dot local hostname' => 'https://ai.internal.local/v1',
    'private IPv4' => 'https://10.0.0.5/v1',
    'link-local IPv4' => 'https://169.254.10.20/v1',
    'loopback IPv6' => 'https://[::1]/v1',
    'link-local IPv6' => 'https://[fe80::1]/v1',
]);

it('fails explicitly when an encrypted provider key cannot be decrypted', function (): void {
    AiProviderConfig::query()->updateOrCreate(['provider' => 'openai']);
    DB::table('ai_provider_configs')->where('provider', 'openai')->update([
        'api_key' => 'not-valid-laravel-ciphertext',
    ]);

    expect(fn () => app(AiProviderConfigService::class)->status('openai'))
        ->toThrow(RuntimeException::class, 'Controleer APP_KEY');
});

it('imports legacy provider settings once without overwriting database changes', function (): void {
    $environment = Env::getRepository();
    $legacyValues = [
        'AI_EXTERNAL_ENABLED' => 'true',
        'AI_EXTERNAL_API_KEY' => 'legacy-external-secret',
        'AI_EXTERNAL_ENDPOINT' => 'https://legacy-ai.example.test',
        'AI_PROVIDER_REGION' => 'EU',
        'AI_RETENTION_NOTICE' => 'No training.',
        'AI_EXTERNAL_VISION_MODEL' => 'legacy-vision',
        'AI_EXTERNAL_EMBEDDING_MODEL' => 'legacy-embedding',
        'AI_EXTERNAL_MONTHLY_BUDGET_CENTS' => '2500',
        'AI_OPENAI_API_KEY' => 'legacy-openai-secret',
        'AI_OPENAI_VISION_MODEL' => 'legacy-openai-model',
        'AI_OPENAI_MONTHLY_BUDGET_CENTS' => '1000',
    ];
    $originalValues = [];

    foreach ($legacyValues as $key => $value) {
        $originalValues[$key] = $environment->get($key);
        $environment->set($key, $value);
    }

    try {
        Schema::dropIfExists('ai_provider_config_audits');
        Schema::dropIfExists('ai_provider_configs');

        $migration = require database_path('migrations/2026_09_27_100000_create_ai_provider_configs_table.php');
        $migration->up();

        $external = DB::table('ai_provider_configs')->where('provider', 'external')->first();
        $openAi = DB::table('ai_provider_configs')->where('provider', 'openai')->first();

        expect(DB::table('ai_provider_configs')->count())->toBe(5)
            ->and((bool) $external->enabled)->toBeTrue()
            ->and($external->vision_model)->toBe('legacy-vision')
            ->and($external->embedding_model)->toBe('legacy-embedding')
            ->and($external->monthly_budget_cents)->toBe(2500)
            ->and(Crypt::decryptString($external->api_key))->toBe('legacy-external-secret')
            ->and($openAi->vision_model)->toBe('legacy-openai-model')
            ->and(Crypt::decryptString($openAi->api_key))->toBe('legacy-openai-secret');

        DB::table('ai_provider_configs')->where('provider', 'external')->update([
            'vision_model' => 'saved-in-database',
        ]);
        $environment->set('AI_EXTERNAL_VISION_MODEL', 'changed-after-import');
        Schema::dropIfExists('ai_provider_config_audits');

        $migration->up();

        expect(DB::table('ai_provider_configs')->count())->toBe(5)
            ->and(DB::table('ai_provider_configs')->where('provider', 'external')->value('vision_model'))->toBe('saved-in-database')
            ->and(Schema::hasTable('ai_provider_config_audits'))->toBeTrue();
    } finally {
        foreach ($originalValues as $key => $value) {
            if ($value === null) {
                $environment->clear($key);
            } else {
                $environment->set($key, $value);
            }
        }
    }
});
