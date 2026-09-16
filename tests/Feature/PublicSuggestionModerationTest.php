<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\License;
use App\Modules\Publication\Models\AssetSuggestion;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function suggestionModerator(string $roleKey = 'editor'): User
{
    $user = User::query()->create(['name' => 'moderator', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

    return $user;
}

function suggestionPublishedAsset(User $owner, array $publicationOverrides = []): array
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Markt op zaterdag', 'lock_version' => 1]);
    $prefix = 'derivatives/'.str()->ulid().'/';
    $derivatives = ['preview300' => $prefix.'preview300.jpg', 'preview1200' => $prefix.'preview1200.jpg', 'preview2000' => $prefix.'preview2000.jpg'];
    foreach ($derivatives as $key) {
        Storage::disk('local')->put($key, 'fake-jpeg-bytes');
    }
    AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $prefix.'original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => 2000, 'pixel_height' => 1000, 'derivatives' => $derivatives,
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
    ]);
    $license = License::query()->firstOrCreate(['code' => 'cc-by'], ['name' => 'CC BY', 'url' => 'https://example.test/cc-by']);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief', 'license_id' => $license->id]);
    $publication = Publication::query()->create(array_replace([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
        'permalink_slug' => 'markt-'.str()->random(6), 'download_policy' => 'preview_only',
    ], $publicationOverrides));

    return [$asset, $publication];
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('lets an anonymous visitor submit a bounded suggestion for an eligible photo', function (): void {
    [$asset, $publication] = suggestionPublishedAsset(suggestionModerator());

    $this->post(route('public.photo.suggest', $publication), [
        'suggestion_type' => 'identification',
        'message' => 'Dit is mijn opa, tweede van links.',
        'submitter_name' => 'Jan',
    ])->assertRedirect(route('public.photo', $publication));

    expect(AssetSuggestion::query()->count())->toBe(1);
    $suggestion = AssetSuggestion::query()->sole();
    expect($suggestion->status)->toBe('pending')
        ->and($suggestion->asset_id)->toBe($asset->id)
        ->and($suggestion->submitter_name)->toBe('Jan')
        ->and($suggestion->ip_hash)->not->toBeEmpty();
});

it('rejects a suggestion whose honeypot field is filled without storing it', function (): void {
    [, $publication] = suggestionPublishedAsset(suggestionModerator());

    $this->post(route('public.photo.suggest', $publication), [
        'suggestion_type' => 'correction',
        'message' => 'Bot bericht.',
        'website' => 'https://spam.example',
    ])->assertSessionHasErrors('website');

    expect(AssetSuggestion::query()->count())->toBe(0);
});

it('rejects a suggestion message beyond the bounded length', function (): void {
    [, $publication] = suggestionPublishedAsset(suggestionModerator());

    $this->post(route('public.photo.suggest', $publication), [
        'suggestion_type' => 'correction',
        'message' => str_repeat('a', 2001),
    ])->assertSessionHasErrors('message');

    expect(AssetSuggestion::query()->count())->toBe(0);
});

it('404s a suggestion submitted against a photo that is not currently public', function (): void {
    [, $publication] = suggestionPublishedAsset(suggestionModerator(), ['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'test']);

    $this->post(route('public.photo.suggest', $publication), [
        'suggestion_type' => 'correction',
        'message' => 'Klopt niet.',
    ])->assertNotFound();

    expect(AssetSuggestion::query()->count())->toBe(0);
});

it('rate-limits anonymous suggestion submissions', function (): void {
    [, $publication] = suggestionPublishedAsset(suggestionModerator());

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('public.photo.suggest', $publication), [
            'suggestion_type' => 'correction',
            'message' => 'Bericht nummer '.$i,
        ])->assertRedirect();
    }

    $this->post(route('public.photo.suggest', $publication), [
        'suggestion_type' => 'correction',
        'message' => 'Bericht nummer 6',
    ])->assertStatus(429);

    expect(AssetSuggestion::query()->count())->toBe(5);
});

it('lets a moderator accept a suggestion while auditing the decision without touching asset metadata', function (): void {
    $moderator = suggestionModerator();
    [$asset, $publication] = suggestionPublishedAsset($moderator);
    $suggestion = AssetSuggestion::query()->create([
        'asset_id' => $asset->id, 'publication_id' => $publication->id, 'suggestion_type' => 'identification',
        'message' => 'Dit is mijn opa.', 'ip_hash' => hash('sha256', 'test'), 'status' => 'pending',
    ]);

    $this->actingAs($moderator)
        ->post(route('admin.suggestions.accept', $suggestion), ['moderator_note' => 'Klinkt aannemelijk.'])
        ->assertRedirect(route('admin.suggestions.index'));

    $suggestion->refresh();
    expect($suggestion->status)->toBe('accepted')
        ->and($suggestion->moderator_user_id)->toBe($moderator->id)
        ->and($suggestion->moderated_at)->not->toBeNull();
    expect($asset->fresh()->title)->toBe('Markt op zaterdag');
    expect($asset->fresh()->auditEvents()->where('event_type', 'suggestion.accepted')->exists())->toBeTrue();
});

it('denies a viewer without assets.update from moderating a suggestion', function (): void {
    $viewer = suggestionModerator('viewer');
    [$asset, $publication] = suggestionPublishedAsset(suggestionModerator());
    $suggestion = AssetSuggestion::query()->create([
        'asset_id' => $asset->id, 'publication_id' => $publication->id, 'suggestion_type' => 'correction',
        'message' => 'Klopt niet.', 'ip_hash' => hash('sha256', 'test'), 'status' => 'pending',
    ]);

    $this->actingAs($viewer)
        ->post(route('admin.suggestions.accept', $suggestion), [])
        ->assertForbidden();

    expect($suggestion->fresh()->status)->toBe('pending');
});
