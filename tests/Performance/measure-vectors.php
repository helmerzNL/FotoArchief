<?php

declare(strict_types=1);

use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\PerformanceContracts;

require __DIR__.'/bootstrap.php';
$budgets = PerformanceContracts::budgets();
$guard = require __DIR__.'/guard.php';
$count = DB::table('ai_embeddings')->whereRaw('vector_dims(embedding_vector) = 384')->whereNull('embedding')->count();
if ($count !== 50000) {
    throw new RuntimeException('Expected exactly 50k actual 384-dimensional pgvector rows.');
}
$generation = AiEmbeddingGeneration::query()->where('status', 'active')->sole();
$query = array_fill(0, 384, 0);
$query[0] = 1;
$times = [];
$lastQuery = null;
DB::listen(static function (QueryExecuted $event) use (&$lastQuery): void {
    if (str_contains($event->sql, '<=>')) {
        $lastQuery = $event;
    }
});
$iterations = $budgets['samples']['warmup'] + $budgets['samples']['measured'];
for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $start = hrtime(true);
    $results = app(PgvectorEmbeddingStore::class)->nearest($generation, $query, 5);
    if (array_column($results, 'accession_number') !== ['BENCH-000000', 'BENCH-000001', 'BENCH-000002', 'BENCH-000003', 'BENCH-000004']) {
        throw new RuntimeException('Actual application vector adapter returned an incorrect exact-cosine ranking.');
    }
    if ($iteration >= $budgets['samples']['warmup']) {
        $times[] = (hrtime(true) - $start) / 1e6;
    }
}
sort($times, SORT_NUMERIC);
$p95 = $times[(int) ceil(count($times) * 0.95) - 1];
$limit = $budgets['p95_ms']['pgvector_exact_cosine'];
$result = [
    'schema_version' => 1, 'budget_contract' => 'budgets.v1.json',
    'scope' => 'actual-application-pgvector-adapter', 'records' => $count, 'dimensions' => 384,
    'metric' => 'exact-cosine', 'ann' => false, 'samples' => count($times), 'p95_ms' => round($p95, 2),
    'limit_ms' => $limit, 'passed' => $p95 < $limit, 'synthetic_vectors' => true,
];
PerformanceContracts::writeResult($guard['root'], 'pgvector', $result);
if ($p95 >= $limit) {
    if ($lastQuery instanceof QueryExecuted) {
        $plan = DB::selectOne(
            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$lastQuery->sql, $lastQuery->bindings,
        );
        echo json_encode(['actual_query_plan' => json_decode($plan->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    }
    throw new RuntimeException("Actual pgvector adapter p95 exceeds {$limit} ms.");
}
