<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\LocalAiProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::query()->create([
        'name' => 'Local AI Admin',
        'email' => 'local-ai@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
});

function enableLocalAi(User $user): void
{
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'image_analysis_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 0,
    ], $user);
}

it('refuses local AI calls until explicitly enabled', function (): void {
    expect(fn () => app(LocalAiProvider::class)->probe())
        ->toThrow(AiProviderException::class, 'Local AI provider is not explicitly enabled');
});

it('probes local AI capabilities and rejects incompatible embedding spaces', function (): void {
    enableLocalAi($this->user);
    Http::fake([
        'http://127.0.0.1:8088/v1/capabilities' => Http::response([
            'provider_kind' => 'local',
            'image_analysis' => true,
            'image_embeddings' => true,
            'text_embeddings' => true,
            'same_embedding_space' => true,
            'model_space' => 'openclip-proof:512:cosine',
            'dimensions' => 512,
        ]),
    ]);

    $capabilities = app(LocalAiProvider::class)->probe();

    expect($capabilities['model_space'])->toBe('openclip-proof:512:cosine');
    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8088/v1/capabilities');
});

it('sends local image analysis requests without external fallback', function (): void {
    enableLocalAi($this->user);
    Http::fake([
        'http://127.0.0.1:8088/v1/analyze-image' => Http::response([
            'description' => 'Een dorpsstraat met historische gevels.',
            'tags' => ['dorpsstraat', 'gevels'],
            'objects' => ['straat', 'gebouw'],
        ]),
    ]);

    $result = app(LocalAiProvider::class)->analyzeImage('image-bytes', ['asset_id' => 'asset-1']);

    expect($result['description'])->toContain('dorpsstraat')
        ->and($result['tags'])->toContain('gevels');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'http://127.0.0.1:8088/v1/analyze-image'
            && ($payload['sha256'] ?? null) === hash('sha256', 'image-bytes')
            && ($payload['language'] ?? null) === 'nl';
    });
});

it('validates local text and image embedding dimensions', function (): void {
    enableLocalAi($this->user);
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-image' => Http::response([
            'embedding' => [0.1, 0.2, 0.3],
            'model_space' => 'openclip-proof:3:cosine',
            'dimensions' => 3,
        ]),
        'http://127.0.0.1:8088/v1/embed-text' => Http::response([
            'embedding' => [0.3, 0.2],
            'model_space' => 'openclip-proof:3:cosine',
            'dimensions' => 3,
        ]),
    ]);

    expect(app(LocalAiProvider::class)->embedImage('image-bytes')['dimensions'])->toBe(3);

    expect(fn () => app(LocalAiProvider::class)->embedText('dorpsstraat'))
        ->toThrow(AiProviderException::class, 'dimensions do not match');
});
