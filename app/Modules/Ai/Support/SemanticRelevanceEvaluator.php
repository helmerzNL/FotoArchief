<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use InvalidArgumentException;

final class SemanticRelevanceEvaluator
{
    /**
     * @param  list<array{query: string, expected_ids: list<string>, ranked_ids: list<string>}>  $queries
     * @return array{precision_at_k: float, recall_at_k: float, reciprocal_rank: float, queries: int}
     */
    public function evaluate(array $queries, int $k): array
    {
        if ($queries === [] || $k < 1) {
            throw new InvalidArgumentException('Corpus must contain queries and k must be at least 1.');
        }

        $precision = 0.0;
        $recall = 0.0;
        $reciprocalRank = 0.0;
        foreach ($queries as $query) {
            $expected = array_values(array_unique($query['expected_ids'] ?? []));
            $ranked = array_values(array_unique(array_slice($query['ranked_ids'] ?? [], 0, $k)));
            if (($query['query'] ?? '') === '' || $expected === []) {
                throw new InvalidArgumentException('Every query requires text and at least one approved expected ID.');
            }
            $hits = count(array_intersect($ranked, $expected));
            $precision += $hits / $k;
            $recall += $hits / count($expected);
            foreach ($ranked as $position => $id) {
                if (in_array($id, $expected, true)) {
                    $reciprocalRank += 1 / ($position + 1);
                    break;
                }
            }
        }

        $count = count($queries);

        return [
            'precision_at_k' => $precision / $count,
            'recall_at_k' => $recall / $count,
            'reciprocal_rank' => $reciprocalRank / $count,
            'queries' => $count,
        ];
    }
}
