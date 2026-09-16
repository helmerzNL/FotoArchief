<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Security finding (HIGH): the public predicate (Publication::scopePubliclyVisible())
 * and the public controllers (viewer, media stream, IIIF manifest) previously
 * only checked *existence* of an eligible (ready_private + clean) asset_files
 * row via firstWhere()/whereHas(), never *uniqueness*, and there was no
 * database-level notion of "the" current file for an asset at all.
 *
 * That gap is now closed by Operations' real `asset_files.is_primary` /
 * `asset_versions.is_current` columns (migration
 * `2026_09_17_230000_add_asset_file_versioning_support.php`) and the partial
 * unique index `asset_files_single_primary_per_asset` (migration
 * `2026_09_17_240000_enforce_single_primary_asset_file.php`), which makes "at
 * most one primary file per asset" a database-enforced invariant rather than
 * something every reader has to trust. These tests exercise that real,
 * integrated schema directly - no column is simulated with Schema::table()
 * here. See docs/CONTRACT_ACTIVE_FILE.md for the full cross-module contract.
 */
uses(RefreshDatabase::class);

function activeFileUser(string $role): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function activeFileAsset(User $owner): Asset
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Brug over de gracht', 'lock_version' => 1]);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief']);

    return $asset;
}

/**
 * Mirrors the real ingest shape: a fresh file defaults to `is_primary = true`
 * (the migration's own default), exactly like ImageProcessor's first ingest
 * for an asset. Pass `primary: false` to create a superseded/non-primary
 * file, which the caller must first ensure does not collide with an existing
 * primary (see the partial unique index).
 */
function activeFileEligibleFile(Asset $asset, bool $primary = true): AssetFile
{
    $prefix = 'derivatives/'.str()->ulid().'/';
    $derivatives = ['preview300' => $prefix.'preview300.jpg', 'preview1200' => $prefix.'preview1200.jpg', 'preview2000' => $prefix.'preview2000.jpg'];
    foreach ($derivatives as $key) {
        Storage::disk('local')->put($key, 'fake-jpeg-bytes');
    }

    return AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $prefix.'original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => 2000, 'pixel_height' => 1000, 'derivatives' => $derivatives,
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
        'is_primary' => $primary,
    ]);
}

function activeFilePublication(Asset $asset): Publication
{
    return Publication::query()->create([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
        'permalink_slug' => 'brug-'.str()->random(6), 'download_policy' => 'preview_only',
    ]);
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('keeps today\'s ordinary single-file ingest fully public across predicate, viewer, media and IIIF, unchanged', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    activeFileEligibleFile($asset);
    $publication = activeFilePublication($asset);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    $this->get(route('public.photo', $publication))->assertOk();
    $this->get(route('public.photo.media', [$publication, 'preview1200']))->assertOk();
    $this->get(route('iiif.manifest', $publication))->assertOk();
});

it('lets the database itself refuse a second primary file for one asset, proving the real invariant', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    activeFileEligibleFile($asset);

    expect(fn () => activeFileEligibleFile($asset))->toThrow(UniqueConstraintViolationException::class);
});

it('serves only the primary file and ignores a superseded non-primary file on the same asset', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    $primary = activeFileEligibleFile($asset, primary: true);
    // A second, non-primary file can coexist (e.g. a retained previous scan);
    // the unique index only constrains is_primary = true rows.
    activeFileEligibleFile($asset, primary: false);
    $publication = activeFilePublication($asset);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    expect($asset->refresh()->load('files')->currentPublicFile()?->id)->toBe($primary->id);
    $this->get(route('public.photo', $publication))->assertOk();
});

it('fails closed when no file on the asset is currently flagged primary', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    $file = activeFileEligibleFile($asset);
    $publication = activeFilePublication($asset);
    // Simulate the in-flight instant of a replace where the previous primary
    // has been demoted but no new primary has been created yet - the
    // predicate and every public route must deny the asset rather than
    // guess, exactly like a zero-file asset.
    $file->update(['is_primary' => false]);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
    $this->get(route('public.photo', $publication))->assertNotFound();
    $this->get(route('public.photo.media', [$publication, 'preview1200']))->assertNotFound();
    $this->get(route('iiif.manifest', $publication))->assertNotFound();
});

it('invalidates the published review the instant the primary file switches, until it is re-reviewed', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    $original = activeFileEligibleFile($asset);
    $publication = activeFilePublication($asset);
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();

    // Mirrors Operations' real ImageProcessor/FileVersionService primary-switch
    // sequence exactly: demote the old primary, ingest the replacement as the
    // new primary, and bump assets.lock_version so the open publication is
    // rejected instead of silently continuing to serve (or being assumed to
    // serve) the superseded file.
    $replacement = null;
    DB::transaction(function () use ($asset, &$replacement): void {
        AssetFile::query()->where('asset_id', $asset->id)->update(['is_primary' => false]);
        $replacement = activeFileEligibleFile($asset, primary: true);
        $asset->increment('lock_version');
    });

    // Exactly one primary file exists again, but the publication now needs
    // re-review: the predicate must deny it publicly instead of serving the
    // replacement without a fresh privacy/rights check.
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
    expect($publication->fresh()->needsReReview())->toBeTrue();
    $this->get(route('public.photo', $publication))->assertNotFound();

    // Once staff re-review and republish at the new lock_version, the
    // replacement file - and only the replacement file - becomes servable.
    $publication->update(['published_lock_version' => $asset->fresh()->lock_version]);
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    expect($asset->refresh()->load('files')->currentPublicFile()?->id)->toBe($replacement->id);
    $this->get(route('public.photo', $publication))->assertOk();
});
