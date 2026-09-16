<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Real-PostgreSQL confirmation of the Finding 2 fix (exact-count whereHas()
 * and the is_primary forward-compat guard): sqlite alone was judged
 * insufficient, since Postgres is the only production engine and the
 * `whereHas($relation, $callback, '=', 1)` exact-count subquery and the
 * runtime `Schema::hasColumn('asset_files', 'is_primary')` guard must be
 * proven against it, not only inferred from sqlite passing. Mirrors the
 * connection-swap + full-migration pattern used by PhotoUpgradeTest and
 * PostgresInstallationTest; requires its own disposable, empty database
 * supplied via FOTOARCHIEF_TEST_PG_PORTAL_DATABASE, and is skipped (not
 * faked) without it, exactly like those two.
 */
it('keeps the exactly-one-eligible-file predicate and is_primary guard correct on PostgreSQL', function (): void {
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
        $makeFile = function () use ($asset): AssetFile {
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
        };
        $first = $makeFile();
        $publication = Publication::query()->create([
            'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
            'permalink_slug' => 'pg-portal-'.str()->random(6), 'download_policy' => 'preview_only',
        ]);

        // Baseline: one eligible file, exactly like today's real ingest.
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
        $this->get(route('public.photo', $publication))->assertOk();

        // A second eligible file appears (future retained-file scenario):
        // the exact-count whereHas(...,'=',1) must now fail closed on real
        // Postgres, and so must the viewer route it gates.
        $second = $makeFile();
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
        $this->get(route('public.photo', $publication))->assertNotFound();

        // Once is_primary lands, Schema::hasColumn(...) must be seen live on
        // Postgres too, and exactly one flagged primary must resolve.
        Schema::table('asset_files', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false);
        });
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
        AssetFile::query()->whereKey($first->id)->update(['is_primary' => true]);
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeTrue();
        expect($asset->refresh()->currentPublicFile()?->id)->toBe($first->id);
        $this->get(route('public.photo', $publication))->assertOk();

        AssetFile::query()->whereKey($second->id)->update(['is_primary' => true]);
        expect(Publication::query()->publiclyVisible()->whereKey($publication->id)->exists())->toBeFalse();
        $this->get(route('public.photo', $publication))->assertNotFound();
    } finally {
        DB::disconnect('pgsql');
    }
})->group('postgres')->skip(fn (): bool => getenv('FOTOARCHIEF_TEST_PG_PORTAL_DATABASE') === false,
    'Requires a separate, empty PostgreSQL database ending in _portal_test.');
