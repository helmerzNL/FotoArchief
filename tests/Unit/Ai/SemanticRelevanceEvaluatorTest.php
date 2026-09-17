<?php

declare(strict_types=1);

use App\Modules\Ai\Support\SemanticRelevanceEvaluator;

it('calculates deterministic ranked relevance metrics from approved judgments', function (): void {
    $metrics = (new SemanticRelevanceEvaluator)->evaluate([
        ['query' => 'molen', 'expected_ids' => ['A', 'B'], 'ranked_ids' => ['A', 'X', 'B']],
        ['query' => 'markt', 'expected_ids' => ['C'], 'ranked_ids' => ['X', 'C']],
    ], 2);

    expect($metrics)->toBe([
        'precision_at_k' => 0.5,
        'recall_at_k' => 0.75,
        'reciprocal_rank' => 0.75,
        'queries' => 2,
    ]);
});

it('rejects a corpus without approved expected IDs', function (): void {
    expect(fn () => (new SemanticRelevanceEvaluator)->evaluate([
        ['query' => 'molen', 'expected_ids' => [], 'ranked_ids' => ['A']],
    ], 1))->toThrow(InvalidArgumentException::class);
});
