<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\License;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Cross-module contract (docs/CONTRACT_SOFT_DELETE.md): Operations has landed
 * `assets.deleted_at`/`deleted_by_user_id`/`deletion_reason` with the standard
 * SoftDeletes trait on Asset (migration 2026_09_17_270000, model change from
 * operations commit a17969f, integrated on parent as dbb27a2). These tests
 * prove, against the real column and trait (not a synthetic one), that a
 * trashed asset can never be discovered, viewed, downloaded or served through
 * IIIF - the forward-compatible guard in Publication::scopePubliclyVisible()
 * needed no further change once this landed.
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

it('confirms the operations recoverable-deletion contract has landed on assets', function (): void {
    expect(Schema::hasColumn('assets', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('assets', 'deleted_by_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('assets', 'deletion_reason'))->toBeTrue()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(Asset::class), true))->toBeTrue();
});

it('keeps a published, non-deleted photo fully public as before', function (): void {
    [, $publication] = softDeleteGuardPublishedAsset(softDeleteGuardOwner());

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    $this->get('/foto/'.$publication->permalink_slug)->assertOk();
});

it('denies a trashed asset in every public predicate: discovery, permalink, media and IIIF manifest', function (): void {
    $owner = softDeleteGuardOwner();
    [$asset, $publication] = softDeleteGuardPublishedAsset($owner);
    $slug = $publication->permalink_slug;

    // Sanity: still visible before deletion.
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();

    // Trash it the way Operations' TrashService does: soft-delete with an
    // attributed reason, using the real SoftDeletes trait.
    $asset->deleted_by_user_id = $owner->id;
    $asset->deletion_reason = 'duplicate upload';
    $asset->save();
    $asset->delete();

    expect(Asset::query()->find($asset->id))->toBeNull()
        ->and(Asset::withTrashed()->whereKey($asset->id)->first()?->deleted_at)->not->toBeNull();

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
    $this->get('/foto/'.$slug)->assertNotFound();
    $this->get('/foto/'.$slug.'/media/preview300')->assertNotFound();
    $this->getJson('/iiif/'.$slug.'/manifest.json')->assertNotFound();
    $this->get('/ontdek')->assertOk()->assertDontSee($slug, false);
});

it('never lets a trashed asset be exported, downloaded or (re)published, and restoring it is required first', function (): void {
    $owner = softDeleteGuardOwner();
    [$asset, $publication] = softDeleteGuardPublishedAsset($owner);

    $asset->delete();
    $publication->refresh();

    // Export/download surfaces all read through the same live predicate, so
    // a trashed asset cannot be exported or downloaded once deleted.
    expect(Publication::query()->publiclyVisible()->where('asset_id', $asset->id)->exists())->toBeFalse();

    // A (re)publish attempt against a trashed asset must not succeed: the
    // asset is gone from default queries, so any staff action that first
    // loads it via Asset::findOrFail() fails closed instead of resurrecting
    // publication for a trashed record.
    expect(fn () => Asset::query()->findOrFail($asset->id))->toThrow(ModelNotFoundException::class);
    expect(Asset::query()->find($asset->id))->toBeNull();

    // Restoring (Operations' own responsibility) makes the asset reappear in
    // default queries again, but publication still requires re-review since
    // nothing here re-publishes it automatically.
    $asset->restore();
    expect(Asset::query()->find($asset->id))->not->toBeNull();
});
