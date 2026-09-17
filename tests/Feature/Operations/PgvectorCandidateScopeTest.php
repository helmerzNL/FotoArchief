<?php

declare(strict_types=1);

use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'candidate-test',
        'model_space' => 'candidate-test:3:cosine',
        'dimensions' => 3,
        'distance_metric' => 'cosine',
        'vector_backend' => 'pgvector',
        'status' => AiEmbeddingGeneration::STATUS_ACTIVE,
    ]);
    $this->asset = Asset::query()->create([
        'accession_number' => 'VECTOR-CANDIDATE',
        'title' => 'Candidate',
    ])->fresh();
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/candidate.jpg',
        'sha256' => hash('sha256', 'candidate'),
        'media_type' => 'image/jpeg',
        'byte_size' => 1,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
    $this->embedding = AiEmbedding::query()->create([
        'ai_embedding_generation_id' => $this->generation->id,
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => $this->asset->lock_version,
        'source_file_sha256' => $this->file->sha256,
        'indexed_at' => now(),
    ]);
});

it('admits only current clean source rows in an active pgvector generation', function (): void {
    expect(app(PgvectorEmbeddingStore::class)->currentCandidates($this->generation)->pluck('ai_embeddings.id')->all())
        ->toBe([$this->embedding->id]);
});

it('applies model visibility on the joined asset without a duplicate asset lookup', function (): void {
    $query = app(PgvectorEmbeddingStore::class)->currentCandidates($this->generation);

    expect($query->from)->toBe('assets')
        ->and(substr_count($query->toSql(), 'from "assets"'))->toBe(1)
        ->and($query->pluck('ai_embeddings.id')->all())->toBe([$this->embedding->id]);
});

it('excludes invalid sources and generations before vector ranking', function (string $change): void {
    match ($change) {
        'stale' => $this->embedding->update(['stale_at' => now()]),
        'checksum' => $this->embedding->update(['source_file_sha256' => str_repeat('0', 64)]),
        'revision' => $this->asset->increment('lock_version'),
        'non-primary' => $this->file->update(['is_primary' => false]),
        'scan' => $this->file->update(['scanner_status' => 'unavailable']),
        'ingest' => $this->file->update(['ingest_status' => 'quarantined']),
        'deleted' => $this->asset->delete(),
        'building' => $this->generation->update(['status' => AiEmbeddingGeneration::STATUS_BUILDING]),
        'retired' => $this->generation->update(['status' => AiEmbeddingGeneration::STATUS_RETIRED]),
        'json' => $this->generation->update(['vector_backend' => 'database_json']),
    };

    expect(app(PgvectorEmbeddingStore::class)->currentCandidates($this->generation)->exists())->toBeFalse();
})->with(['stale', 'checksum', 'revision', 'non-primary', 'scan', 'ingest', 'deleted', 'building', 'retired', 'json']);

it('rejects a file belonging to a different asset even when its checksum matches the embedding', function (): void {
    $other = Asset::query()->create(['accession_number' => 'VECTOR-OTHER'])->fresh();
    $this->embedding->update([
        'asset_id' => $other->id,
        'source_asset_lock_version' => $other->lock_version,
    ]);

    expect(app(PgvectorEmbeddingStore::class)->currentCandidates($this->generation)->exists())->toBeFalse();
});

it('applies the authorized asset subquery before the result limit', function (bool $windowed): void {
    $authorized = Asset::query()->create(['accession_number' => 'VECTOR-AUTHORIZED'])->fresh();
    $file = AssetFile::query()->create([
        'asset_id' => $authorized->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/authorized.jpg',
        'sha256' => hash('sha256', 'authorized'),
        'media_type' => 'image/jpeg',
        'byte_size' => 1,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
    AiEmbedding::query()->create([
        'ai_embedding_generation_id' => $this->generation->id,
        'asset_id' => $authorized->id,
        'asset_file_id' => $file->id,
        'source_asset_lock_version' => $authorized->lock_version,
        'source_file_sha256' => $file->sha256,
        'indexed_at' => now(),
    ]);
    $scope = Asset::query()->select('assets.id')->whereKey($authorized->id)->toBase();
    if ($windowed) {
        $scope = Asset::query()->select('assets.id')
            ->whereIn('assets.id', [$this->asset->id, $authorized->id])
            ->orderByDesc('accession_number')->offset(1)->limit(1)->toBase();
    }
    $originalSql = $scope->toSql();
    $query = app(PgvectorEmbeddingStore::class)->currentCandidates($this->generation, $scope);

    expect($query->count())->toBe(1)
        ->and($query->limit(1)->pluck('ai_embeddings.asset_id')->all())->toBe([$authorized->id])
        ->and($scope->toSql())->toBe($originalSql);
})->with(['direct authorization' => false, 'windowed authorization' => true]);
