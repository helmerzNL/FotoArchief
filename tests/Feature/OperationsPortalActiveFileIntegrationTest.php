<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\FileVersionService;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Proves the operations and portal contracts actually hold together, by driving
 * the portal's real public predicate and resolver with the operations services
 * that change which file a dossier serves. The portal's own consistency test
 * simulates the is_primary column; here the column is the real one, with the
 * partial unique index behind it.
 *
 * This test is written against Operations' real `ArchiveOperations` module
 * (`FileVersionService`, `TrashService`), which is developed on a separate
 * branch and is not part of this worktree. It is skipped here - not faked -
 * until that module is present (i.e. once Parent merges this branch together
 * with Operations'), so it never reports a false pass and never blocks this
 * branch's own suite. Provided verbatim (Operations' scratch integration
 * already proved it green on the merged tree) so Parent does not have to
 * write it a second time after integration.
 */
uses(RefreshDatabase::class);

function opsPortalUser(string $role): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function opsPortalAsset(User $owner): Asset
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Brug over de gracht', 'lock_version' => 1]);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief']);

    return $asset;
}

function opsPortalFile(Asset $asset, bool $isPrimary): AssetFile
{
    $prefix = 'derivatives/'.str()->ulid().'/';
    $derivatives = ['preview300' => $prefix.'preview300.jpg', 'preview1200' => $prefix.'preview1200.jpg', 'preview2000' => $prefix.'preview2000.jpg'];
    foreach ($derivatives as $key) {
        Storage::disk('local')->put($key, 'fake-jpeg-bytes');
    }
    Storage::disk('local')->put($prefix.'original.jpg', 'fake-original-bytes');

    $file = AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $prefix.'original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => 2000, 'pixel_height' => 1000, 'derivatives' => $derivatives,
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(),
        'processed_at' => now(), 'scanned_at' => now(), 'is_primary' => $isPrimary,
    ]);

    $next = (int) (AssetVersion::query()->where('asset_id', $asset->id)->max('version_number') ?? 0) + 1;
    AssetVersion::query()->create([
        'asset_id' => $asset->id, 'asset_file_id' => $file->id, 'version_number' => $next,
        'change_type' => $next === 1 ? 'initial_scan' : 'rescan', 'is_current' => $isPrimary,
    ]);

    return $file;
}

function opsPortalPublication(Asset $asset): Publication
{
    return Publication::query()->create([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true,
        'published_lock_version' => (int) $asset->lock_version,
        'permalink_slug' => 'brug-'.str()->random(6), 'download_policy' => 'preview_only',
    ]);
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('serves a retained older scan to nobody: the resolver follows the operations primary flag', function (): void {
    $owner = opsPortalUser('editor');
    $asset = opsPortalAsset($owner);

    $original = opsPortalFile($asset, true);
    $retained = opsPortalFile($asset, false);

    $publication = opsPortalPublication($asset);

    // Two retained eligible files, exactly one primary: the portal predicate
    // must still see the dossier as public and resolve the primary file.
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();

    $resolved = $asset->fresh()->load('files')->currentPublicFile();

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($original->id)
        ->and($resolved->id)->not->toBe($retained->id);
})->skip(fn (): bool => ! class_exists(FileVersionService::class),
    'Requires Operations\' ArchiveOperations module (merged branch only).');

it('withdraws the dossier from public view until re-review when operations activates another version', function (): void {
    $owner = opsPortalUser('editor');
    $asset = opsPortalAsset($owner);

    $original = opsPortalFile($asset, true);
    $replacement = opsPortalFile($asset, false);
    $publication = opsPortalPublication($asset);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();

    app(FileVersionService::class)->setActiveVersion($asset->fresh(), $replacement, $owner);

    $asset->refresh();

    // The served bytes changed, so the published review is stale and the
    // dossier leaves public view without a second coordinated change.
    expect((int) $asset->lock_version)->toBe((int) $publication->published_lock_version + 1)
        ->and(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();

    // And it never resolves to the superseded file.
    expect($asset->load('files')->currentPublicFile()->id)->toBe($replacement->id)
        ->and($original->fresh()->is_primary)->toBeFalse();

    $this->get('/foto/'.$publication->permalink_slug)->assertNotFound();
    $this->get('/foto/'.$publication->permalink_slug.'/media/preview1200')->assertNotFound();
    $this->get('/iiif/'.$publication->permalink_slug.'/manifest.json')->assertNotFound();
})->skip(fn (): bool => ! class_exists(FileVersionService::class),
    'Requires Operations\' ArchiveOperations module (merged branch only).');

it('denies every public route once operations moves the dossier to the trash', function (): void {
    $owner = opsPortalUser('editor');
    $asset = opsPortalAsset($owner);
    opsPortalFile($asset, true);
    $publication = opsPortalPublication($asset);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();

    app(TrashService::class)->moveToTrash($asset->fresh(), 'Verzoek van rechthebbende.', $owner);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();

    $this->get('/foto/'.$publication->permalink_slug)->assertNotFound();
    $this->get('/foto/'.$publication->permalink_slug.'/media/preview1200')->assertNotFound();
    $this->get('/iiif/'.$publication->permalink_slug.'/manifest.json')->assertNotFound();
})->skip(fn (): bool => ! class_exists(TrashService::class),
    'Requires Operations\' ArchiveOperations module (merged branch only).');

it('cannot be put into the two-primary state the portal predicate defends against', function (): void {
    $owner = opsPortalUser('editor');
    $asset = opsPortalAsset($owner);
    opsPortalFile($asset, true);

    expect(fn () => opsPortalFile($asset, true))
        ->toThrow(UniqueConstraintViolationException::class);
});
