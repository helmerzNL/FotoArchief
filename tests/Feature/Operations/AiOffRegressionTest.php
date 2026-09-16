<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Services\AiDispatchService;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Regression coverage for the "AI stays off by default" requirement: with no
// AiConfigurationService::update() call at all (config/ai.php's untouched
// defaults), every AI-reachable surface must refuse to run and must never
// contact an external provider.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->user = User::query()->create([
        'name' => 'AI Off Admin',
        'email' => 'ai-off@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->asset = Asset::query()->create([
        'accession_number' => 'AI-OFF-'.(string) str()->ulid(),
        'title' => 'AI-off contract',
        'created_by_user_id' => $this->user->id,
    ])->fresh();
    Storage::disk('local')->put('originals/ai-off.jpg', 'safe-derived-image-bytes');
    AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/ai-off.jpg',
        'sha256' => hash('sha256', 'safe-derived-image-bytes'),
        'media_type' => 'image/jpeg',
        'byte_size' => 12345,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
});

it('refuses image analysis dispatch while AI is off by default', function (): void {
    expect(fn () => app(AiDispatchService::class)->dispatchImageAnalysis([$this->asset->id], 'local', $this->user))
        ->toThrow(ValidationException::class, 'AI-beeldanalyse is niet actief');
});

it('refuses embedding index dispatch while AI is off by default', function (): void {
    expect(fn () => app(AiDispatchService::class)->dispatchEmbeddingIndex([$this->asset->id], 'local', $this->user))
        ->toThrow(ValidationException::class, 'AI-embeddings zijn niet actief');
});

it('refuses admin semantic search while AI is off by default', function (): void {
    expect(fn () => app(AiSemanticSearchService::class)->searchAdmin('een dorpsplein', 'local', $this->user))
        ->toThrow(ValidationException::class, 'AI-embeddings zijn niet actief');
});

it('refuses public semantic search and falls back to plain listing while AI is off by default', function (): void {
    Publication::query()->create([
        'asset_id' => $this->asset->id,
        'status' => 'published',
        'privacy_cleared' => true,
        'published_lock_version' => (int) $this->asset->lock_version,
        'permalink_slug' => 'ai-off-'.str()->random(6),
        'download_policy' => 'preview_only',
    ]);

    $response = $this->get('/ontdek?'.http_build_query([
        'semantic_q' => 'een dorpsplein',
        'semantic_consent' => '1',
    ]));

    $response->assertOk();
    $response->assertViewHas('semanticError', 'AI-embeddings zijn niet actief.');
});
