<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\ExternalAiProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::query()->create([
        'name' => 'External AI Admin',
        'email' => 'external-ai@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
});

function enableExternalAi(User $user): void
{
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'image_analysis_enabled' => '1',
        'embeddings_enabled' => '1',
        'external_provider_enabled' => '1',
        'external_processing_allowed' => '1',
        'external_endpoint' => 'https://external-ai.example.test',
        'provider_region' => 'EU',
        'retention_notice' => 'No training; thirty day abuse logs.',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 2500,
    ], $user);
}

it('refuses external AI until endpoint consent and budget are explicit', function (): void {
    expect(fn () => app(ExternalAiProvider::class)->probe())
        ->toThrow(AiProviderException::class, 'External AI provider is not explicitly enabled');
});

it('probes external AI capabilities with runtime-only bearer token', function (): void {
    enableExternalAi($this->user);
    config(['ai.external_api_key' => 'runtime-secret-token']);
    Http::fake([
        'https://external-ai.example.test/v1/capabilities' => Http::response([
            'provider_kind' => 'external',
            'image_analysis' => true,
            'image_embeddings' => true,
            'text_embeddings' => true,
            'same_embedding_space' => true,
            'model_space' => 'external-proof:1024:cosine',
            'dimensions' => 1024,
        ]),
    ]);

    $capabilities = app(ExternalAiProvider::class)->probe();

    expect($capabilities['provider_kind'])->toBe('external');
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer runtime-secret-token')
        && $request->url() === 'https://external-ai.example.test/v1/capabilities');
});

it('sends external privacy context and validates embeddings', function (): void {
    enableExternalAi($this->user);
    Http::fake([
        'https://external-ai.example.test/v1/analyze-image' => Http::response([
            'description' => 'Een marktplein met historische bebouwing.',
            'tags' => ['marktplein', 'bebouwing'],
        ]),
        'https://external-ai.example.test/v1/embed-text' => Http::response([
            'embedding' => [0.1, 0.2],
            'model_space' => 'external-proof:2:cosine',
            'dimensions' => 2,
        ]),
    ]);

    $analysis = app(ExternalAiProvider::class)->analyzeImage('external-bytes', ['asset_id' => 'asset-1']);
    $embedding = app(ExternalAiProvider::class)->embedText('marktplein');

    expect($analysis['tags'])->toContain('marktplein')
        ->and($embedding['dimensions'])->toBe(2);

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://external-ai.example.test/v1/analyze-image') {
            return false;
        }
        $payload = $request->data();

        return ($payload['privacy']['provider_region'] ?? null) === 'EU'
            && ($payload['privacy']['external_budget_cents'] ?? null) === 2500
            && ($payload['sha256'] ?? null) === hash('sha256', 'external-bytes');
    });
});
