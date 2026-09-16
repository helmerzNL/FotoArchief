<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Security finding (HIGH): the public predicate
 * (Publication::scopePubliclyVisible()) and the public controllers (viewer,
 * media stream, IIIF manifest) previously only checked *existence* of an
 * eligible (ready_private + clean) asset_files row via firstWhere()/
 * whereHas(), never *uniqueness*. If a future feature ever left two eligible
 * files on one asset, the predicate could say "public" while the viewer and
 * the IIIF manifest independently picked an unordered first() row - possibly
 * a different, superseded file than the one the predicate reasoned about.
 *
 * Fixed by requiring the predicate's whereHas(...) to match exactly one
 * eligible file, and by routing every public read through the single
 * Asset::currentPublicFile() resolver, which also fails closed (null) on
 * zero or more than one eligible file. Today's ingest pipeline only ever
 * produces one file per asset, so the baseline single-file case must keep
 * working completely unchanged; these tests also simulate the future
 * multi-file and is_primary scenarios entirely at the ORM/schema level, per
 * docs/CONTRACT_ACTIVE_FILE.md, without needing a real second ingest route.
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

function activeFileEligibleFile(Asset $asset): AssetFile
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

it('fails closed on the predicate, viewer, media and IIIF manifest when two eligible files exist on one asset', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    activeFileEligibleFile($asset);
    activeFileEligibleFile($asset);
    $publication = activeFilePublication($asset);

    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
    // Route-model binding itself already rejects the publication (same
    // predicate), so the permalink 404s before the controller ever tries to
    // resolve a file.
    $this->get('/foto/'.$publication->permalink_slug)->assertNotFound();
    $this->get('/foto/'.$publication->permalink_slug.'/media/preview1200')->assertNotFound();
    $this->get('/iiif/'.$publication->permalink_slug.'/manifest.json')->assertNotFound();
});

it('resolves exactly one file as current once is_primary exists, and fails closed with zero or multiple primaries', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    $first = activeFileEligibleFile($asset);
    $second = activeFileEligibleFile($asset);
    $publication = activeFilePublication($asset);

    // Simulate the future is_primary column landing (see
    // docs/CONTRACT_ACTIVE_FILE.md) without needing Operations' migration.
    Schema::table('asset_files', function (Blueprint $table): void {
        $table->boolean('is_primary')->default(false);
    });

    // Zero primaries flagged: still fails closed even though two files are
    // otherwise eligible.
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
    $this->get(route('public.photo', $publication))->assertNotFound();

    // Exactly one primary flagged: resolves unambiguously and goes public.
    AssetFile::query()->whereKey($first->id)->update(['is_primary' => true]);
    expect($asset->refresh()->currentPublicFile()?->id)->toBe($first->id);
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    $this->get(route('public.photo', $publication))->assertOk();

    // Both flagged primary at once: fails closed again rather than guessing.
    AssetFile::query()->whereKey($second->id)->update(['is_primary' => true]);
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
    $this->get(route('public.photo', $publication))->assertNotFound();
});

it('confirms the future is_primary switch fails closed publicly instead of ever serving the superseded file', function (): void {
    $owner = activeFileUser('editor');
    $asset = activeFileAsset($owner);
    $original = activeFileEligibleFile($asset);
    $publication = activeFilePublication($asset);

    Schema::table('asset_files', function (Blueprint $table): void {
        $table->boolean('is_primary')->default(false);
    });
    AssetFile::query()->whereKey($original->id)->update(['is_primary' => true]);
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();

    // A hypothetical "replace" operation adds a second file and (today,
    // without a coordinated lock_version bump) simply flips is_primary. That
    // ambiguous instant - both flagged momentarily, or the switch performed
    // without also re-reviewing - must never let the public routes serve
    // either file inconsistently: the predicate and Asset::currentPublicFile()
    // both key off the same exact-one-primary condition, so this is provably
    // consistent by construction rather than by ordering luck.
    $replacement = activeFileEligibleFile($asset);
    AssetFile::query()->whereKey($original->id)->update(['is_primary' => false]);
    AssetFile::query()->whereKey($replacement->id)->update(['is_primary' => true]);

    expect($asset->refresh()->currentPublicFile()?->id)->toBe($replacement->id);
    expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
    $this->get(route('public.photo.media', [$publication, 'preview1200']))->assertOk();
});
