<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Real-PostgreSQL confirmation of the active-file predicate against
 * Operations' real, integrated `is_primary`/`is_current` schema (migrations
 * `2026_09_17_230000_add_asset_file_versioning_support.php` and
 * `2026_09_17_240000_enforce_single_primary_asset_file.php`, both present in
 * this worktree's `database/migrations/`). sqlite alone was judged
 * insufficient since Postgres is the only production engine and the partial
 * unique index `asset_files_single_primary_per_asset` must be proven there,
 * not only inferred from sqlite passing. Mirrors the connection-swap +
 * full-migration pattern used by PhotoUpgradeTest and
 * PostgresInstallationTest; requires its own disposable, empty database
 * supplied via FOTOARCHIEF_TEST_PG_PORTAL_DATABASE, and is skipped (not
 * faked) without it, exactly like those two.
 */
it('keeps the primary-file predicate and its database invariant correct on PostgreSQL', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_PORTAL_DATABASE');
    expect($database)->toEndWith('_portal_test');
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => getenv('FOTOARCHIEF_TEST_PG_HOST') ?: '127.0.0.1',
        'database.connections.pgsql.port' => getenv('FOTOARCHIEF_TEST_PG_PORT') ?: 5432,
        'database.connections.pgsql.database' => $database,
        'database.connections.pgsql.username' => getenv('FOTOARCHIEF_TEST_PG_USER'),
        'database.connections.pgsql.password' => getenv('FOTOARCHIEF_TEST_PG_PASSWORD'),
        'database.connections.pgsql.sslmode' => 'prefer',
        'filesystems.default' => 'local',
    ]);
    DB::purge('pgsql');
    Storage::fake('local');

    try {
        expect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->toBe([]);
        Artisan::call('migrate', ['--force' => true]);
        $this->seed(DatabaseSeeder::class);

        $owner = User::query()->create(['name' => 'PG portal tester', 'email' => 'pg-portal@example.test', 'password' => 'disposable-pg-portal-password']);
        $owner->roles()->attach(Role::query()->where('key', 'editor')->firstOrFail());
        $asset = Asset::query()->create(['accession_number' => 'PG-PORTAL-001', 'created_by_user_id' => $owner->id, 'title' => 'PG-test foto', 'lock_version' => 1]);
        $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief']);
        $makeFile = function (bool $primary = true) use ($asset): AssetFile {
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
        };
        $first = $makeFile();
        $publication = Publication::query()->create([
            'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
            'permalink_slug' => 'pg-portal-'.str()->random(6), 'download_policy' => 'preview_only',
        ]);

        // Baseline: one primary, eligible file, exactly like today's real ingest.
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
        $this->get(route('public.photo', $publication))->assertOk();

        // The partial unique index must refuse a second primary on real Postgres.
        expect(fn () => $makeFile())->toThrow(UniqueConstraintViolationException::class);

        // Demoting the sole primary without yet creating a replacement fails
        // closed rather than guessing.
        $first->update(['is_primary' => false]);
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
        $this->get(route('public.photo', $publication))->assertNotFound();

        // Switching primaries (mirroring Operations' real ImageProcessor
        // sequence) bumps lock_version and requires re-review before the
        // replacement can go public again.
        $replacement = null;
        DB::transaction(function () use (&$replacement, $makeFile, $asset): void {
            $replacement = $makeFile();
            $asset->increment('lock_version');
        });
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
        $publication->update(['published_lock_version' => $asset->fresh()->lock_version]);
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
        expect($asset->refresh()->load('files')->currentPublicFile()?->id)->toBe($replacement->id);
        $this->get(route('public.photo', $publication))->assertOk();
    } finally {
        DB::disconnect('pgsql');
    }
})->group('postgres')->skip(fn (): bool => getenv('FOTOARCHIEF_TEST_PG_PORTAL_DATABASE') === false,
    'Requires a separate, empty PostgreSQL database ending in _portal_test.');
