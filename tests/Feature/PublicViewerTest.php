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

function viewerOwner(): User
{
    $user = User::query()->create(['name' => 'owner', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'editor')->firstOrFail());

    return $user;
}

function viewerPublishedAsset(User $owner, array $publicationOverrides = []): array
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Kade bij avond', 'description' => 'Een historische kade.', 'date_display' => 'circa 1930', 'lock_version' => 1]);
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
        'permalink_slug' => 'kade-'.str()->random(6), 'credit_line' => 'Foto: Gemeentearchief', 'download_policy' => 'preview_only',
    ], $publicationOverrides));

    return [$asset, $publication];
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('shows the public homepage without private content and with public nav for guests', function (): void {
    $response = $this->get('/')->assertOk();
    $response->assertSee('Ontdekken', false)->assertSee('Collecties', false)->assertSee('Inloggen', false);
});

it('renders the permalink viewer with credits rights permalink download and xss-safe structured data', function (): void {
    [$asset, $publication] = viewerPublishedAsset(viewerOwner());

    $response = $this->get('/foto/'.$publication->permalink_slug)->assertOk();
    $response->assertSee('Kade bij avond')
        ->assertSee('Gemeentearchief')
        ->assertSee('Foto: Gemeentearchief')
        ->assertSee(route('public.photo', $publication), false)
        ->assertSee('Download voorbeeldweergave');
});

it('escapes an attacker-controlled title inside the structured data script tag', function (): void {
    $owner = viewerOwner();
    [$asset, $publication] = viewerPublishedAsset($owner);
    $asset->update(['title' => '</script><script>alert(1)</script>']);
    $publication->update(['published_lock_version' => $asset->fresh()->lock_version]);

    $response = $this->get('/foto/'.$publication->permalink_slug)->assertOk();
    $response->assertDontSee('</script><script>alert(1)</script>', false);
});

it('gates the download link on the publication download policy', function (): void {
    [$asset, $publication] = viewerPublishedAsset(viewerOwner(), ['download_policy' => 'none']);
    $this->get('/foto/'.$publication->permalink_slug)->assertOk()->assertDontSee('Download voorbeeldweergave');
    $this->get(route('public.photo.media', [$publication, 'preview2000', 'download' => 1]))->assertForbidden();
    $this->get(route('public.photo.media', [$publication, 'preview300']))->assertOk();
});

it('404s the viewer and its media for a revoked photo immediately', function (): void {
    [$asset, $publication] = viewerPublishedAsset(viewerOwner());
    $slug = $publication->permalink_slug;
    $publication->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'test']);

    $this->get('/foto/'.$slug)->assertNotFound();
    $this->get('/foto/'.$slug.'/media/preview300')->assertNotFound();
});

it('lists only eligible photos in segmented sitemaps and 404s out of range pages', function (): void {
    $owner = viewerOwner();
    [, $visible] = viewerPublishedAsset($owner);
    [, $embargoed] = viewerPublishedAsset($owner, ['embargo_until' => now()->addWeek()->toDateString()]);

    $index = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
    $index->assertSee(route('public.sitemap.photos', 1), false);

    $photos = $this->get('/sitemap-fotos-1.xml')->assertOk();
    $photos->assertSee(route('public.photo', $visible), false);
    $photos->assertDontSee(route('public.photo', $embargoed), false);

    $this->get('/sitemap-fotos-2.xml')->assertNotFound();
});
