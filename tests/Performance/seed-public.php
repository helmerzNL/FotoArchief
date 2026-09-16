<?php

declare(strict_types=1);

use App\Modules\Publication\Models\Publication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
if (DB::table('assets')->count() !== 50000 || DB::table('publications')->exists()
    || DB::table('asset_files')->exists() || DB::table('asset_rights')->exists()) {
    throw new RuntimeException('Public benchmark requires the untouched 50,000-record metadata fixture.');
}
DB::transaction(function (): void {
    $collection = (string) Str::ulid();
    DB::table('collections')->insert([
        'id' => $collection, 'title' => 'Synthetische straten', 'slug' => 'benchmark-straten',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $number = 0;
    DB::table('assets')->orderBy('id')->chunkById(1000, function ($assets) use ($collection, &$number): void {
        $files = $rights = $publications = $members = $deleted = [];
        foreach ($assets as $asset) {
            $case = $number % 100;
            $files[] = [
                'id' => (string) Str::ulid(), 'asset_id' => $asset->id,
                'storage_disk' => 'local', 'storage_key' => 'synthetic-benchmark/'.$asset->id,
                'sha256' => hash('sha256', 'synthetic-metadata-only-'.$asset->id),
                'media_type' => 'image/png', 'byte_size' => 1,
                'ingest_status' => $case === 99 ? 'queued' : 'ready_private',
                'scanner_status' => in_array($case, [94, 95], true) ? 'unscanned' : 'clean',
                'created_at' => now(), 'updated_at' => now(),
            ];
            $rights[] = [
                'id' => (string) Str::ulid(), 'asset_id' => $asset->id,
                'verification_status' => $case === 98 ? 'unverified' : 'verified',
                'rights_holder' => 'Synthetische benchmark, geen beeldmateriaal',
                'created_at' => now(), 'updated_at' => now(),
            ];
            $publications[] = [
                'id' => (string) Str::ulid(), 'asset_id' => $asset->id,
                'status' => match ($case) {
                    90 => 'draft', 91 => 'revoked', default => 'published'
                },
                'privacy_cleared' => $case !== 92,
                'embargo_until' => $case === 93 ? now()->addYear()->toDateString() : null,
                'published_lock_version' => $case === 96 ? 0 : $asset->lock_version,
                'published_at' => now()->subSeconds($number),
                'permalink_slug' => 'benchmark-'.strtolower($asset->accession_number),
                'download_policy' => 'preview_only', 'created_at' => now(), 'updated_at' => now(),
            ];
            if ($case === 97) {
                $deleted[] = $asset->id;
            }
            if ($number % 5 === 0) {
                $members[] = [
                    'id' => (string) Str::ulid(), 'asset_id' => $asset->id, 'collection_id' => $collection,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $number++;
        }
        DB::transaction(function () use ($files, $rights, $publications, $members, $deleted): void {
            DB::table('asset_files')->insert($files);
            DB::table('asset_rights')->insert($rights);
            DB::table('publications')->insert($publications);
            DB::table('collection_assets')->insert($members);
            DB::table('assets')->whereIn('id', $deleted)->update(['deleted_at' => now()]);
        });
    });
    foreach (['assets', 'asset_files', 'asset_rights', 'publications', 'collection_assets', 'collections'] as $table) {
        DB::statement('ANALYZE '.$table);
    }
    $eligible = Publication::query()->publiclyVisible()->count();
    if ($eligible !== 45000) {
        throw new RuntimeException("Expected exactly 45,000 eligible publications, got {$eligible}.");
    }
});
echo "Public benchmark: 50,000 assets, 45,000 eligible publications, 5,000 denied; no image binaries.\n";
