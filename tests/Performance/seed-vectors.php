<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
if (DB::table('assets')->count() !== 50000 || DB::table('asset_files')->count() !== 50000
    || DB::table('ai_embeddings')->exists() || DB::table('ai_embedding_generations')->exists()) {
    throw new RuntimeException('Vector benchmark requires untouched 50k publication fixture and empty vector tables.');
}
DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
app(PgvectorEmbeddingStore::class)->provision();
$settings = app(AiConfigurationService::class);
$settings->update([
    'global_enabled' => '1', 'embeddings_enabled' => '1', 'local_provider_enabled' => '1',
    'local_endpoint' => getenv('SMOKE_URL'), 'embeddings_provider' => 'local',
    'embeddings_model' => 'synthetic-benchmark-384',
    'max_assets_per_batch' => 10, 'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15,
], User::query()->where('email', 'benchmark@example.test')->firstOrFail());
$generation = AiEmbeddingGeneration::query()->create([
    'provider_kind' => 'local', 'provider_name' => 'synthetic-fixture',
    'model_id' => 'synthetic-benchmark-384', 'model_space' => 'synthetic-benchmark-384',
    'requested_model' => (string) $settings->effective()['embeddings_model'],
    'dimensions' => 384, 'distance_metric' => 'cosine', 'vector_backend' => 'pgvector',
    'status' => 'active', 'activated_at' => now(),
]);
DB::table('ai_embedding_heads')->insert(['provider_kind' => 'local', 'generation_id' => $generation->id]);
DB::table('asset_files')->join('assets', 'assets.id', '=', 'asset_files.asset_id')
    ->select('asset_files.id', 'asset_files.asset_id', 'asset_files.sha256', 'assets.lock_version', 'assets.accession_number')
    ->chunkById(500, function ($files) use ($generation): void {
        $rows = [];
        foreach ($files as $file) {
            $number = (int) substr($file->accession_number, 6);
            $vector = array_fill(0, 384, 0);
            if ($number < 15) {
                $vector[intdiv($number, 5)] = 1;
            } else {
                for ($dimension = 0; $dimension < 384; $dimension++) {
                    $vector[$dimension] = (($number * 31 + $dimension * 17) % 101 + 1) / 101;
                }
            }
            $rows[] = [
                'id' => (string) Str::ulid(), 'ai_embedding_generation_id' => $generation->id,
                'asset_id' => $file->asset_id, 'asset_file_id' => $file->id,
                'source_asset_lock_version' => $file->lock_version, 'source_file_sha256' => $file->sha256,
                'embedding_vector' => json_encode($vector, JSON_THROW_ON_ERROR), 'indexed_at' => now(),
                'metadata' => '{"synthetic":true,"provider_called":false,"image_bytes":false}',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('ai_embeddings')->insert($rows);
    }, 'asset_files.id', 'id');
DB::statement('ANALYZE ai_embeddings');
$count = DB::table('ai_embeddings')->whereRaw('vector_dims(embedding_vector) = 384')->whereNull('embedding')->count();
if ($count !== 50000) {
    throw new RuntimeException('Expected 50,000 actual 384-dimensional pgvector rows, without JSON fallback.');
}
echo "Seeded exactly 50,000 actual 384-dimensional pgvector rows; no JSON fallback or provider calls.\n";
