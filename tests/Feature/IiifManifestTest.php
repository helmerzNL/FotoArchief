<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\License;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function iiifOwner(): User
{
    $user = User::query()->create(['name' => 'owner', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'editor')->firstOrFail());

    return $user;
}

function iiifPublishedAsset(User $owner, array $publicationOverrides = [], int $width = 4000, int $height = 2000): array
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Haven bij zonsondergang', 'description' => 'Uitzicht op de haven.', 'lock_version' => 1]);
    $prefix = 'derivatives/'.str()->ulid().'/';
    $derivatives = ['preview300' => $prefix.'preview300.jpg', 'preview1200' => $prefix.'preview1200.jpg', 'preview2000' => $prefix.'preview2000.jpg'];
    foreach ($derivatives as $key) {
        Storage::disk('local')->put($key, 'fake-jpeg-bytes');
    }
    AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $prefix.'original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => $width, 'pixel_height' => $height, 'derivatives' => $derivatives,
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
    ]);
    $license = License::query()->firstOrCreate(['code' => 'cc-by'], ['name' => 'CC BY', 'url' => 'https://example.test/cc-by']);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief', 'license_id' => $license->id]);
    $publication = Publication::query()->create(array_replace([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
        'permalink_slug' => 'haven-'.str()->random(6), 'credit_line' => 'Foto: Gemeentearchief', 'download_policy' => 'preview_only',
    ], $publicationOverrides));

    return [$asset, $publication];
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('serves a valid IIIF Presentation 3 manifest for an eligible published photo', function (): void {
    [$asset, $publication] = iiifPublishedAsset(iiifOwner(), [], 4000, 2000);

    $response = $this->getJson(route('iiif.manifest', $publication))->assertOk();
    $response->assertJson([
        '@context' => 'http://iiif.io/api/presentation/3/context.json',
        'type' => 'Manifest',
        'id' => route('iiif.manifest', $publication),
        'label' => ['nl' => ['Haven bij zonsondergang']],
        'rights' => 'https://example.test/cc-by',
    ]);

    $canvas = $response->json('items.0');
    expect($canvas['type'])->toBe('Canvas')
        ->and($canvas['width'])->toBe(2000)
        ->and($canvas['height'])->toBe(1000);

    $body = $response->json('items.0.items.0.items.0.body');
    expect($body['type'])->toBe('Image')
        ->and($body['format'])->toBe('image/jpeg')
        ->and($body['id'])->toBe(route('public.photo.media', [$publication, 'preview2000']))
        ->and($body['width'])->toBe(2000)
        ->and($body['height'])->toBe(1000);
});

it('carries an attacker-controlled description as inert JSON data, never as a raw closing script tag', function (): void {
    $owner = iiifOwner();
    [$asset, $publication] = iiifPublishedAsset($owner);
    $asset->update(['description' => '</script><script>alert(1)</script>']);

    $response = $this->getJson(route('iiif.manifest', $publication))->assertOk();
    // The value round-trips correctly through the JSON structure...
    expect($response->json('summary.nl.0'))->toBe('</script><script>alert(1)</script>');
    // ...but the raw response body never contains a literal, breakout-capable
    // "</script>" because Laravel's JSON encoder escapes forward slashes.
    expect($response->getContent())->not->toContain('</script>');
});

it('404s the manifest for a photo that is not currently public', function (): void {
    [, $embargoed] = iiifPublishedAsset(iiifOwner(), ['embargo_until' => now()->addWeek()->toDateString()]);
    $this->getJson(route('iiif.manifest', $embargoed))->assertNotFound();
});

it('404s the manifest immediately once a published photo is revoked', function (): void {
    [, $publication] = iiifPublishedAsset(iiifOwner());
    $publication->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'test']);

    $this->getJson(route('iiif.manifest', $publication))->assertNotFound();
});

it('404s the manifest for a draft that was never published', function (): void {
    $owner = iiifOwner();
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Nooit gepubliceerd', 'lock_version' => 1]);
    $publication = Publication::query()->create(['asset_id' => $asset->id, 'status' => 'draft', 'privacy_cleared' => false]);

    $this->getJson('/iiif/'.($publication->permalink_slug ?? 'onbekend').'/manifest.json')->assertNotFound();
});
