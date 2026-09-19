<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class PerformanceContracts
{
    /**
     * @return array{
     *   schema_version: int,
     *   dataset_records: int,
     *   samples: array{warmup: int, measured: int, concurrency: int},
     *   p95_ms: array<string, int>
     * }
     */
    public static function budgets(): array
    {
        $data = self::decode('budgets.v1.json');
        $samples = $data['samples'] ?? null;
        $limits = $data['p95_ms'] ?? null;
        if (($data['schema_version'] ?? null) !== 1
            || ($data['dataset_records'] ?? null) !== 50000
            || ! is_array($samples)
            || ! is_int($samples['warmup'] ?? null)
            || ! is_int($samples['measured'] ?? null)
            || ! is_int($samples['concurrency'] ?? null)
            || $samples['warmup'] < 0
            || $samples['measured'] < 1
            || $samples['concurrency'] < 1
            || ! is_array($limits)) {
            throw new RuntimeException('Performance budget contract is invalid.');
        }

        $validatedLimits = [];
        foreach ($limits as $name => $limit) {
            if (! is_string($name) || ! is_int($limit) || $limit < 1) {
                throw new RuntimeException('Performance budget limits must be named positive integers.');
            }
            $validatedLimits[$name] = $limit;
        }

        return [
            'schema_version' => 1,
            'dataset_records' => 50000,
            'samples' => [
                'warmup' => $samples['warmup'],
                'measured' => $samples['measured'],
                'concurrency' => $samples['concurrency'],
            ],
            'p95_ms' => $validatedLimits,
        ];
    }

    /**
     * @return array{
     *   schema_version: int,
     *   dataset: string,
     *   k: int,
     *   thresholds: array{precision_at_k: float|int, recall_at_k: float|int, reciprocal_rank: float|int},
     *   queries: list<array{query: string, expected_ids: list<string>}>,
     *   acceptance_boundary: string
     * }
     */
    public static function relevanceDataset(): array
    {
        $data = self::decode('relevance-dataset.v1.json');
        $thresholds = $data['thresholds'] ?? null;
        if (($data['schema_version'] ?? null) !== 1
            || ! is_string($data['dataset'] ?? null)
            || ! is_int($data['k'] ?? null)
            || $data['k'] < 1
            || ! is_array($thresholds)
            || (! is_int($thresholds['precision_at_k'] ?? null) && ! is_float($thresholds['precision_at_k'] ?? null))
            || (! is_int($thresholds['recall_at_k'] ?? null) && ! is_float($thresholds['recall_at_k'] ?? null))
            || (! is_int($thresholds['reciprocal_rank'] ?? null) && ! is_float($thresholds['reciprocal_rank'] ?? null))
            || ! is_array($data['queries'] ?? null)
            || ! is_string($data['acceptance_boundary'] ?? null)) {
            throw new RuntimeException('Semantic relevance dataset contract is invalid.');
        }

        $queries = [];
        foreach ($data['queries'] as $query) {
            if (! is_array($query) || ! is_string($query['query'] ?? null) || ! is_array($query['expected_ids'] ?? null)) {
                throw new RuntimeException('Semantic relevance query is invalid.');
            }
            $expectedIds = [];
            foreach ($query['expected_ids'] as $expectedId) {
                if (! is_string($expectedId)) {
                    throw new RuntimeException('Semantic relevance identifiers must be strings.');
                }
                $expectedIds[] = $expectedId;
            }
            if ($query['query'] === '' || $expectedIds === []) {
                throw new RuntimeException('Semantic relevance queries require text and expected identifiers.');
            }
            $queries[] = ['query' => $query['query'], 'expected_ids' => $expectedIds];
        }

        if ($queries === []) {
            throw new RuntimeException('Semantic relevance dataset must contain queries.');
        }

        return [
            'schema_version' => 1,
            'dataset' => $data['dataset'],
            'k' => $data['k'],
            'thresholds' => [
                'precision_at_k' => $thresholds['precision_at_k'],
                'recall_at_k' => $thresholds['recall_at_k'],
                'reciprocal_rank' => $thresholds['reciprocal_rank'],
            ],
            'queries' => $queries,
            'acceptance_boundary' => $data['acceptance_boundary'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function writeResult(string $root, string $name, array $result): void
    {
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($root.'/'.$name.'.json', $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write benchmark result {$name}.");
        }
        echo $json;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $filename): array
    {
        $data = json_decode(file_get_contents(dirname(__DIR__).'/Performance/'.$filename) ?: '', true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException("Performance contract {$filename} must be a JSON object.");
        }

        return $data;
    }
}
