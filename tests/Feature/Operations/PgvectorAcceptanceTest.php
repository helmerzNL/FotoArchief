<?php

declare(strict_types=1);

use Illuminate\Support\Str;

function pgvectorAcceptancePdo(): ?PDO
{
    $database = (string) getenv('FOTOARCHIEF_TEST_PGVECTOR_DATABASE');
    if ($database === '') {
        return null;
    }

    $host = (string) (getenv('FOTOARCHIEF_TEST_PGVECTOR_HOST') ?: '127.0.0.1');
    $port = (string) (getenv('FOTOARCHIEF_TEST_PGVECTOR_PORT') ?: '5432');
    $user = (string) (getenv('FOTOARCHIEF_TEST_PGVECTOR_USER') ?: 'fotoarchief');
    $password = (string) getenv('FOTOARCHIEF_TEST_PGVECTOR_PASSWORD');

    return new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function pgvectorQuoteIdentifier(string $identifier): string
{
    if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe PostgreSQL identifier.');
    }

    return '"'.$identifier.'"';
}

it('proves pgvector model isolation stale filtering and rebuild semantics when explicitly configured', function (): void {
    $pdo = pgvectorAcceptancePdo();
    if (! $pdo instanceof PDO) {
        $this->markTestSkipped('Set FOTOARCHIEF_TEST_PGVECTOR_* for opt-in real pgvector acceptance.');
    }

    $schema = 'pgvector_acceptance_'.strtolower(Str::random(10));
    $quotedSchema = pgvectorQuoteIdentifier($schema);

    try {
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        $pdo->exec("CREATE SCHEMA {$quotedSchema}");
        $pdo->exec("CREATE TABLE {$quotedSchema}.generations (
            id bigserial PRIMARY KEY,
            provider_kind text NOT NULL,
            model_id text NOT NULL,
            model_space text NOT NULL,
            dimensions integer NOT NULL,
            status text NOT NULL
        )");
        $pdo->exec("CREATE TABLE {$quotedSchema}.embeddings (
            id bigserial PRIMARY KEY,
            generation_id bigint NOT NULL REFERENCES {$quotedSchema}.generations(id),
            asset_id text NOT NULL,
            source_file_sha256 text NOT NULL,
            embedding vector(3) NOT NULL,
            stale_at timestamptz NULL
        )");

        $pdo->exec("INSERT INTO {$quotedSchema}.generations (id, provider_kind, model_id, model_space, dimensions, status) VALUES
            (1, 'local', 'openclip-a', 'openclip-a:3:cosine', 3, 'active'),
            (2, 'local', 'openclip-b', 'openclip-b:3:cosine', 3, 'active'),
            (3, 'local', 'openclip-a', 'openclip-a:3:cosine', 3, 'retired')");
        $pdo->exec("INSERT INTO {$quotedSchema}.embeddings (generation_id, asset_id, source_file_sha256, embedding, stale_at) VALUES
            (1, 'asset-active', 'sha-active', '[1,0,0]', NULL),
            (1, 'asset-stale', 'sha-stale', '[1,0,0]', now()),
            (2, 'asset-other-model', 'sha-other', '[1,0,0]', NULL),
            (3, 'asset-retired-generation', 'sha-retired', '[1,0,0]', NULL)");

        $active = pgvectorAssetIds($pdo, $schema, 'local', 'openclip-a:3:cosine', 3);
        expect($active)->toBe(['asset-active']);

        $pdo->exec("UPDATE {$quotedSchema}.embeddings SET stale_at = now() WHERE asset_id = 'asset-active'");
        $pdo->exec("INSERT INTO {$quotedSchema}.generations (id, provider_kind, model_id, model_space, dimensions, status) VALUES
            (4, 'local', 'openclip-a-rebuilt', 'openclip-a-rebuilt:3:cosine', 3, 'active')");
        $pdo->exec("INSERT INTO {$quotedSchema}.embeddings (generation_id, asset_id, source_file_sha256, embedding, stale_at) VALUES
            (4, 'asset-rebuilt', 'sha-rebuilt', '[0.9,0.1,0]', NULL)");

        expect(pgvectorAssetIds($pdo, $schema, 'local', 'openclip-a:3:cosine', 3))->toBe([])
            ->and(pgvectorAssetIds($pdo, $schema, 'local', 'openclip-a-rebuilt:3:cosine', 3))->toBe(['asset-rebuilt']);
    } finally {
        $pdo->exec("DROP SCHEMA IF EXISTS {$quotedSchema} CASCADE");
    }
});

/**
 * @return list<string>
 */
function pgvectorAssetIds(PDO $pdo, string $schema, string $provider, string $modelSpace, int $dimensions): array
{
    $quotedSchema = pgvectorQuoteIdentifier($schema);
    $statement = $pdo->prepare("SELECT e.asset_id
        FROM {$quotedSchema}.embeddings e
        JOIN {$quotedSchema}.generations g ON g.id = e.generation_id
        WHERE g.provider_kind = :provider
          AND g.model_space = :model_space
          AND g.dimensions = :dimensions
          AND g.status = 'active'
          AND e.stale_at IS NULL
        ORDER BY e.embedding <=> '[1,0,0]'::vector
        LIMIT 10");
    $statement->execute([
        'provider' => $provider,
        'model_space' => $modelSpace,
        'dimensions' => $dimensions,
    ]);

    return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
}
