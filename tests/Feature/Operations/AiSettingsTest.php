<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiProviderConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->admin = User::query()->create([
        'name' => 'AI Admin',
        'email' => 'ai-admin@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->viewer = User::query()->create([
        'name' => 'AI Viewer',
        'email' => 'ai-viewer@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
});

it('keeps AI disabled by default and hides settings from non-admin users', function (): void {
    $this->get('/admin/operations/ai')->assertRedirect('/login');
    $this->actingAs($this->viewer)->get('/admin/operations/ai')->assertStatus(403);

    $response = $this->actingAs($this->admin)->get('/admin/operations/ai');

    $response->assertOk()
        ->assertSee('AI staat standaard uit', false)
        ->assertSee('Actief', false)
        ->assertSee('nee', false);

    $settings = app(AiConfigurationService::class)->effective();
    expect($settings['active'])->toBeFalse()
        ->and($settings['local_ready'])->toBeFalse()
        ->and($settings['external_ready'])->toBeFalse();
});

it('rejects unsafe external endpoints and missing external privacy consent', function (): void {
    $response = $this->actingAs($this->admin)->post('/admin/operations/ai', [
        'global_enabled' => '1',
        'embeddings_enabled' => '1',
        'external_provider_enabled' => '1',
        'external_endpoint' => 'http://127.0.0.1:8000',
        'max_assets_per_batch' => 25,
        'derivative_max_pixels' => 1024,
        'request_timeout_seconds' => 60,
        'monthly_external_budget_cents' => 0,
    ]);

    $response->assertSessionHasErrors([
        'external_endpoint',
        'external_processing_allowed',
        'monthly_external_budget_cents',
        'provider_region',
        'retention_notice',
    ]);
});

it('stores explicit local and external opt-ins without storing credentials', function (): void {
    $response = $this->actingAs($this->admin)->post('/admin/operations/ai', [
        'global_enabled' => '1',
        'image_analysis_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'external_provider_enabled' => '1',
        'external_processing_allowed' => '1',
        'local_endpoint' => 'http://10.0.0.5:8080',
        'external_endpoint' => 'https://ai-provider.example.test/v1',
        'provider_region' => 'EU',
        'retention_notice' => 'No training; 30 day abuse log retention.',
        'max_assets_per_batch' => 12,
        'derivative_max_pixels' => 768,
        'request_timeout_seconds' => 30,
        'monthly_external_budget_cents' => 5000,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/admin/operations/ai');

    $settings = app(AiConfigurationService::class)->effective();
    expect($settings['active'])->toBeTrue()
        ->and($settings['local_ready'])->toBeTrue()
        ->and($settings['external_ready'])->toBeTrue()
        ->and(json_encode($settings, JSON_THROW_ON_ERROR))->not->toContain('secret12345');
});

it('lets the emergency stop override otherwise ready AI settings', function (): void {
    $this->actingAs($this->admin)->post('/admin/operations/ai', [
        'global_enabled' => '1',
        'emergency_stop' => '1',
        'image_analysis_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://localhost:8080',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 0,
    ])->assertSessionHasNoErrors();

    $settings = app(AiConfigurationService::class)->effective();

    expect($settings['active'])->toBeFalse()
        ->and($settings['local_ready'])->toBeFalse()
        ->and($settings['emergency_stop'])->toBeTrue();
});

it('runs a cheap, non-billable OpenAI connection test and reports whether the configured model is visible', function (): void {
    app(AiProviderConfigService::class)->setApiKey('openai', 'sk-test');
    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [['id' => 'gpt-4.1-mini'], ['id' => 'gpt-4.1']],
        ]),
    ]);
    app(AiConfigurationService::class)->update(['image_analysis_model' => 'gpt-4.1-mini'], $this->admin);

    $response = $this->actingAs($this->admin)->post('/admin/operations/ai/test-connection', [
        'test_provider' => 'openai',
        'test_capability' => 'image_analysis',
    ]);

    $response->assertRedirect('/admin/operations/ai');
    $response->assertSessionHas('connection_test', function (array $result): bool {
        return $result['status'] === 'ok'
            && $result['models_count'] === 2
            && $result['model_found'] === true;
    });
});

it('reports a connection-test failure without ever leaking the raw response into the flashed message', function (): void {
    app(AiProviderConfigService::class)->setApiKey('openai', 'sk-test');
    Http::fake([
        'https://api.openai.com/v1/models' => Http::response('geheime-inhoud', 401),
    ]);

    $response = $this->actingAs($this->admin)->post('/admin/operations/ai/test-connection', [
        'test_provider' => 'openai',
        'test_capability' => 'image_analysis',
    ]);

    $response->assertSessionHas('connection_test', function (array $result): bool {
        return $result['status'] === 'error' && ! str_contains($result['message'], 'geheime-inhoud');
    });
});

it('denies a non-admin from running a connection test', function (): void {
    $this->actingAs($this->viewer)
        ->post('/admin/operations/ai/test-connection', ['test_provider' => 'openai', 'test_capability' => 'image_analysis'])
        ->assertStatus(403);
});

it('rejects a connection test for a provider that does not support the requested capability', function (): void {
    $response = $this->actingAs($this->admin)->post('/admin/operations/ai/test-connection', [
        'test_provider' => 'openai',
        'test_capability' => 'embeddings',
    ]);

    // Route-level validation rejects the provider/capability combination
    // via Rule::in against AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS
    // for test_provider; openai is valid there, so the service layer itself
    // rejects the mismatched capability instead.
    $response->assertSessionHas('connection_test', function (array $result): bool {
        return $result['status'] === 'error';
    });
});
