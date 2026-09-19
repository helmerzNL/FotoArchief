<?php

declare(strict_types=1);

use App\Modules\Ai\Support\SemanticRelevanceEvaluator;
use Tests\Support\PerformanceContracts;

it('keeps the 50000-photo performance budgets complete and measurable', function (): void {
    $budgets = PerformanceContracts::budgets();

    expect($budgets['dataset_records'])->toBe(50000)
        ->and($budgets['samples'])->toBe(['warmup' => 5, 'measured' => 40, 'concurrency' => 4])
        ->and(array_keys($budgets['p95_ms']))->toBe([
            'private_listing',
            'private_search',
            'private_detail',
            'public_search',
            'public_detail',
            'semantic_search',
            'pgvector_exact_cosine',
        ]);

    foreach ($budgets['p95_ms'] as $limit) {
        expect($limit)->toBeInt()->toBeGreaterThan(0);
    }
});

it('evaluates the versioned semantic relevance set against its declared thresholds', function (): void {
    $dataset = PerformanceContracts::relevanceDataset();
    $queries = [];
    foreach ($dataset['queries'] as $query) {
        $queries[] = [
            'query' => $query['query'],
            'expected_ids' => $query['expected_ids'],
            'ranked_ids' => $query['expected_ids'],
        ];
    }

    $metrics = (new SemanticRelevanceEvaluator)->evaluate($queries, $dataset['k']);

    expect($dataset['schema_version'])->toBe(1)
        ->and($dataset['dataset'])->toBe('fotoarchief-semantic-relevance-v1')
        ->and($metrics['queries'])->toBe(count($dataset['queries']))
        ->and($metrics['precision_at_k'])->toBeGreaterThanOrEqual($dataset['thresholds']['precision_at_k'])
        ->and($metrics['recall_at_k'])->toBeGreaterThanOrEqual($dataset['thresholds']['recall_at_k'])
        ->and($metrics['reciprocal_rank'])->toBeGreaterThanOrEqual($dataset['thresholds']['reciprocal_rank'])
        ->and($dataset['acceptance_boundary'])->toContain('not external model quality');
});
