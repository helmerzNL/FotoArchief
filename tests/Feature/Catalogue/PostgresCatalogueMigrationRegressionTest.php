<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Worklist;
use App\Modules\Catalogue\Models\WorklistItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('runs all catalogue migrations and enforces PostgreSQL foreign key relationships with ULID users', function (): void {
    $pgPort = env('TEST_PGSQL_PORT', '55449');
    $pdo = null;

    try {
        $pdo = new PDO("pgsql:host=127.0.0.1;port={$pgPort};user=fotoarchief;dbname=postgres");
    } catch (Exception $e) {
        $this->markTestSkipped('PostgreSQL server not available on port '.$pgPort);
    }

    $testDb = 'catalogue_pg_fk_regression_'.bin2hex(random_bytes(4));
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb} WITH (FORCE)");
    $pdo->exec("CREATE DATABASE {$testDb}");

    try {
        config([
            'database.default' => 'pgsql_test',
            'database.connections.pgsql_test' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => (int) $pgPort,
                'database' => $testDb,
                'username' => 'fotoarchief',
                'password' => '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
        ]);

        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();

        // Verify tables exist in PostgreSQL
        expect(Schema::connection('pgsql_test')->hasTable('bulk_operations'))->toBeTrue();
        expect(Schema::connection('pgsql_test')->hasTable('tag_synonyms'))->toBeTrue();
        expect(Schema::connection('pgsql_test')->hasTable('worklists'))->toBeTrue();
        expect(Schema::connection('pgsql_test')->hasTable('worklist_items'))->toBeTrue();

        // Test inserting related records into PostgreSQL ensuring ULID foreign keys work seamlessly
        $user = User::on('pgsql_test')->create([
            'name' => 'PG Test User',
            'email' => 'pgtest@example.test',
            'password' => 'secret123',
        ]);

        $asset = Asset::on('pgsql_test')->create([
            'accession_number' => 'FA-PG-001',
            'title' => 'PostgreSQL Asset',
            'created_by_user_id' => $user->id,
        ]);

        // 1. Bulk operation
        DB::connection('pgsql_test')->table('bulk_operations')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'operation_type' => 'bulk_test',
            'affected_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Worklist and WorklistItem
        $worklist = Worklist::on('pgsql_test')->create([
            'title' => 'PG Curation Worklist',
            'worklist_type' => 'missing_date',
            'created_by_user_id' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        $item = WorklistItem::on('pgsql_test')->create([
            'worklist_id' => $worklist->id,
            'asset_id' => $asset->id,
            'status' => 'pending',
            'completed_by_user_id' => $user->id,
        ]);

        expect($worklist->items()->count())->toBe(1);
        expect($item->asset->accession_number)->toBe('FA-PG-001');

        DB::disconnect('pgsql_test');
    } finally {
        $pdo->exec("DROP DATABASE IF EXISTS {$testDb} WITH (FORCE)");
    }
});
