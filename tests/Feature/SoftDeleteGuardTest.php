<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\License;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Cross-module contract guard (docs/CONTRACT_SOFT_DELETE.md): Operations owns
 * adding `assets.deleted_at` later. These tests simulate that column landing,
 * without depending on Operations' migration existing yet, to prove the
 * Publication predicate already denies a deleted asset the instant the
 * column appears - no second coordinated change required on the Portal side.
 */
uses(RefreshDatabase::class);

function softDeleteGuardOwner(): User
{
    $user = User::query()->create(['name' => 'owner', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'editor')->firstOrFail());

    return $user;
}

function softDeleteGuardPublishedAsset(User $owner): array
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Brug over de gracht', 'lock_version' => 1]);
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
    $publication = Publication::query()->create([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
        'permalink_slug' => 'brug-'.str()->random(6), 'download_policy' => 'preview_only',
    ]);

    return [$asset, $publication];
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('keeps a published photo public when assets has no deleted_at column yet, as today', function (): void {
    expect(Schema::hasColumn('assets', 'deleted_at'))->toBeFalse();
    [, $publication] = softDeleteGuardPublishedAsset(softDeleteGuardOwner());

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    $this->get('/foto/'.$publication->permalink_slug)->assertOk();
});

it('denies a soft-deleted asset in every public predicate the instant deleted_at exists, without a code change here', function (): void {
    [$asset, $publication] = softDeleteGuardPublishedAsset(softDeleteGuardOwner());
    $slug = $publication->permalink_slug;

    // Simulate the column Operations will add; Portal's guard is written to
    // pick this up automatically once it exists.
    Schema::table('assets', function (Blueprint $table): void {
        $table->timestampTz('deleted_at')->nullable();
    });

    try {
        expect(Schema::hasColumn('assets', 'deleted_at'))->toBeTrue();

        // Still visible while deleted_at is null.
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
        $this->get('/foto/'.$slug)->assertOk();

        // Once the asset is (soft-)deleted, every predicate must deny it:
        // discovery/search, the permalink route binding, the media stream,
        // and the IIIF manifest.
        Asset::query()->whereKey($asset->id)->update(['deleted_at' => now()]);

        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
        $this->get('/foto/'.$slug)->assertNotFound();
        $this->get('/foto/'.$slug.'/media/preview300')->assertNotFound();
        $this->getJson('/iiif/'.$slug.'/manifest.json')->assertNotFound();
        $this->get('/ontdek')->assertOk()->assertDontSee($slug, false);
    } finally {
        // RefreshDatabase does not undo raw DDL; leave the schema as this
        // test found it so later tests in the same run see no deleted_at.
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('deleted_at');
        });
    }
});
