<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `is_primary` and `is_current` were added with a default of true and no backfill,
 * so every file of a dossier ingested before that migration claims to be the
 * primary one. A public predicate that filters on `is_primary` and takes the
 * first row would therefore still be able to serve a withdrawn older scan, which
 * is the exact failure the portal predicate is meant to prevent.
 *
 * This migration repairs the existing rows and then makes "at most one primary
 * file per dossier" an invariant the database enforces, so no reader has to
 * trust that every writer remembered to clear the previous primary.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillPrimaryFiles();
        $this->backfillMissingVersions();
        $this->createSinglePrimaryIndex();
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS asset_files_single_primary_per_asset');
        }
    }

    private function backfillPrimaryFiles(): void
    {
        $assetIds = DB::table('asset_files')
            ->select('asset_id')
            ->groupBy('asset_id')
            ->havingRaw('count(*) > 1')
            ->pluck('asset_id');

        foreach ($assetIds as $assetId) {
            // The file carried by the current version wins; otherwise the newest
            // file does, because ingest appends improved scans over time.
            $currentFileId = DB::table('asset_versions')
                ->where('asset_id', $assetId)
                ->where('is_current', true)
                ->orderByDesc('version_number')
                ->value('asset_file_id');

            $keepId = $currentFileId ?? DB::table('asset_files')
                ->where('asset_id', $assetId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            if ($keepId === null) {
                continue;
            }

            DB::table('asset_files')->where('asset_id', $assetId)->update(['is_primary' => false]);
            DB::table('asset_files')->where('id', $keepId)->update(['is_primary' => true]);

            DB::table('asset_versions')->where('asset_id', $assetId)->update(['is_current' => false]);
            DB::table('asset_versions')
                ->where('asset_id', $assetId)
                ->where('asset_file_id', $keepId)
                ->update(['is_current' => true]);
        }
    }

    /**
     * Files ingested before version tracking existed have no asset_versions row
     * at all, so a reader joining through versions would find nothing for them.
     */
    private function backfillMissingVersions(): void
    {
        $orphanFiles = DB::table('asset_files')
            ->whereNotIn('id', DB::table('asset_versions')->select('asset_file_id'))
            ->orderBy('asset_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'asset_id', 'is_primary', 'original_filename', 'created_at']);

        foreach ($orphanFiles as $file) {
            $next = (int) (DB::table('asset_versions')->where('asset_id', $file->asset_id)->max('version_number') ?? 0) + 1;

            DB::table('asset_versions')->insert([
                'id' => (string) Str::ulid(),
                'asset_id' => $file->asset_id,
                'asset_file_id' => $file->id,
                'version_number' => $next,
                'change_type' => $next === 1 ? 'initial_scan' : 'rescan',
                'change_note' => 'Historische opname geregistreerd bij invoering van versiebeheer.',
                'is_current' => (bool) $file->is_primary,
                'created_at' => $file->created_at,
                'updated_at' => now(),
            ]);
        }
    }

    private function createSinglePrimaryIndex(): void
    {
        $driver = DB::connection()->getDriverName();
        $predicate = $driver === 'sqlite' ? 'is_primary = 1' : 'is_primary = true';

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS asset_files_single_primary_per_asset ON asset_files (asset_id) WHERE '.$predicate);
        }
    }
};
