<?php

declare(strict_types=1);

use App\Modules\Ai\Support\SemanticRelevanceEvaluator;
use Illuminate\Support\Facades\Http;

require __DIR__.'/bootstrap.php';

$base = (string) getenv('SMOKE_URL');
if (parse_url($base, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Relevance measurement only targets the owned loopback fixture.');
}
$exitCode = 0;
$queries = [
    ['query' => 'straat met huizen', 'expected_ids' => ['BENCH-000000', 'BENCH-000001', 'BENCH-000002', 'BENCH-000003', 'BENCH-000004']],
    ['query' => 'schepen in de haven', 'expected_ids' => ['BENCH-000005', 'BENCH-000006', 'BENCH-000007', 'BENCH-000008', 'BENCH-000009']],
    ['query' => 'markt op het dorpsplein', 'expected_ids' => ['BENCH-000010', 'BENCH-000011', 'BENCH-000012', 'BENCH-000013', 'BENCH-000014']],
];
foreach ($queries as &$query) {
    $response = Http::timeout(30)->get($base.'/ontdek', [
        'semantic_q' => $query['query'], 'semantic_provider' => 'local', 'semantic_consent' => 1,
    ]);
    if ($response->status() !== 200) {
        throw new RuntimeException('Actual public semantic search route failed: HTTP '.$response->status());
    }
    preg_match_all('~href="[^"]*/foto/benchmark-(bench-[0-9]{6})"~', $response->body(), $matches);
    $query['ranked_ids'] = array_values(array_unique(array_map('strtoupper', $matches[1])));
    if (count($query['ranked_ids']) < 5) {
        throw new RuntimeException('Actual search route did not return five ranked results.');
    }
}
unset($query);
$metrics = (new SemanticRelevanceEvaluator)->evaluate($queries, 5);
echo json_encode([
    'scope' => 'actual-public-http-route-with-deterministic-local-provider',
    'negative_control' => in_array('--negative-control', $argv, true),
    'live_model_quality' => false, 'k' => 5, 'minimum_precision' => 1, 'minimum_recall' => 1,
    'metrics' => $metrics, 'queries' => $queries,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
if ($metrics['precision_at_k'] < 1 || $metrics['recall_at_k'] < 1) {
    fwrite(STDERR, "Relevance thresholds not met.\n");
    $exitCode = 1;
}
exit($exitCode);
