<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\ArchiveOperations\Services\FileVersionService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function primaryInvariantActor(): User
{
    return User::query()->create([
        'name' => 'Versiebeheerder',
        'email' => 'versie-invariant@example.test',
        'password' => Hash::make('disposable-password-invariant'),
        'email_verified_at' => now(),
    ]);
}

function primaryInvariantAsset(User $actor): Asset
{
    return Asset::query()->create([
        'accession_number' => 'FA-INV-'.strtoupper(Str::random(6)),
        'title' => 'Invariantdossier',
        'created_by_user_id' => $actor->id,
        'lock_version' => 1,
    ]);
}

function primaryInvariantFile(Asset $asset, string $suffix, bool $isPrimary): AssetFile
{
    return AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/'.$asset->accession_number.'-'.$suffix.'.jpg',
        'sha256' => hash('sha256', $asset->accession_number.$suffix),
        'media_type' => 'image/jpeg',
        'byte_size' => 1024,
        'original_filename' => $suffix.'.jpg',
        'derivatives' => [],
        'ingest_status' => 'ready_private',
        'is_primary' => $isPrimary,
    ]);
}

it('refuses to let a dossier hold two primary files at once', function (): void {
    $actor = primaryInvariantActor();
    $asset = primaryInvariantAsset($actor);
    primaryInvariantFile($asset, 'one', true);

    expect(fn () => primaryInvariantFile($asset, 'two', true))->toThrow(QueryException::class);

    expect(AssetFile::query()->where('asset_id', $asset->id)->where('is_primary', true)->count())->toBe(1);
});

it('moves the primary flag and the current version together when a version is activated', function (): void {
    $actor = primaryInvariantActor();
    $asset = primaryInvariantAsset($actor);

    $old = primaryInvariantFile($asset, 'old', true);
    $new = primaryInvariantFile($asset, 'new', false);

    foreach ([[$old, 1, true], [$new, 2, false]] as [$file, $number, $current]) {
        AssetVersion::query()->create([
            'asset_id' => $asset->id,
            'asset_file_id' => $file->id,
            'version_number' => $number,
            'change_type' => $number === 1 ? 'initial_scan' : 'rescan',
            'is_current' => $current,
        ]);
    }

    app(FileVersionService::class)->setActiveVersion($asset, $new, $actor);

    expect($old->fresh()->is_primary)->toBeFalse()
        ->and($new->fresh()->is_primary)->toBeTrue()
        ->and(AssetFile::query()->where('asset_id', $asset->id)->where('is_primary', true)->count())->toBe(1)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->where('is_current', true)->count())->toBe(1)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->where('is_current', true)->value('asset_file_id'))->toBe($new->id);
});

it('invalidates an open review when the served file changes', function (): void {
    $actor = primaryInvariantActor();
    $asset = primaryInvariantAsset($actor);

    $old = primaryInvariantFile($asset, 'old', true);
    $new = primaryInvariantFile($asset, 'new', false);

    AssetVersion::query()->create([
        'asset_id' => $asset->id,
        'asset_file_id' => $old->id,
        'version_number' => 1,
        'change_type' => 'initial_scan',
        'is_current' => true,
    ]);
    AssetVersion::query()->create([
        'asset_id' => $asset->id,
        'asset_file_id' => $new->id,
        'version_number' => 2,
        'change_type' => 'rescan',
        'is_current' => false,
    ]);

    $before = (int) $asset->fresh()->lock_version;

    app(FileVersionService::class)->setActiveVersion($asset, $new, $actor);

    expect((int) $asset->fresh()->lock_version)->toBe($before + 1);
});

it('leaves exactly one primary file and one current version per dossier after the repair migration', function (): void {
    $actor = primaryInvariantActor();
    $asset = primaryInvariantAsset($actor);

    // Reproduce the legacy shape: the column default made every historical file
    // claim to be primary, and none of them had a version row at all. The repair
    // migration's own index has to come off first to recreate that state.
    DB::statement('DROP INDEX IF EXISTS asset_files_single_primary_per_asset');

    DB::table('asset_files')->insert([
        [
            'id' => (string) Str::ulid(), 'asset_id' => $asset->id, 'storage_disk' => 'local',
            'storage_key' => 'originals/legacy-old.jpg', 'sha256' => hash('sha256', 'legacy-old'),
            'media_type' => 'image/jpeg', 'byte_size' => 10, 'original_filename' => 'legacy-old.jpg',
            'derivatives' => '[]', 'ingest_status' => 'ready_private', 'is_primary' => true,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ],
        [
            'id' => (string) Str::ulid(), 'asset_id' => $asset->id, 'storage_disk' => 'local',
            'storage_key' => 'originals/legacy-new.jpg', 'sha256' => hash('sha256', 'legacy-new'),
            'media_type' => 'image/jpeg', 'byte_size' => 10, 'original_filename' => 'legacy-new.jpg',
            'derivatives' => '[]', 'ingest_status' => 'ready_private', 'is_primary' => true,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ],
    ]);

    expect(AssetFile::query()->where('asset_id', $asset->id)->where('is_primary', true)->count())->toBe(2);

    $migration = require database_path('migrations/2026_09_17_240000_enforce_single_primary_asset_file.php');
    $migration->up();

    $primaries = AssetFile::query()->where('asset_id', $asset->id)->where('is_primary', true)->get();

    expect($primaries)->toHaveCount(1)
        ->and($primaries->first()->original_filename)->toBe('legacy-new.jpg')
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->count())->toBe(2)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->where('is_current', true)->count())->toBe(1)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->where('is_current', true)->value('asset_file_id'))
        ->toBe($primaries->first()->id);
});
