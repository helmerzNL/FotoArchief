<?php

declare(strict_types=1);

use App\Modules\Ai\Support\SemanticRelevanceEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\Support\PerformanceContracts;

require __DIR__.'/bootstrap.php';
$guard = require __DIR__.'/guard.php';

$base = (string) getenv('SMOKE_URL');
if (parse_url($base, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Relevance measurement only targets the owned loopback fixture.');
}
$exitCode = 0;
$dataset = PerformanceContracts::relevanceDataset();
$queries = $dataset['queries'];
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
$metrics = (new SemanticRelevanceEvaluator)->evaluate($queries, $dataset['k']);
$result = [
    'schema_version' => 1,
    'dataset' => $dataset['dataset'],
    'scope' => 'actual-public-http-route-with-deterministic-local-provider',
    'negative_control' => in_array('--negative-control', $argv, true),
    'live_model_quality' => false,
    'k' => $dataset['k'],
    'thresholds' => $dataset['thresholds'],
    'acceptance_boundary' => $dataset['acceptance_boundary'],
    'metrics' => $metrics, 'queries' => $queries,
];
PerformanceContracts::writeResult($guard['root'], in_array('--negative-control', $argv, true) ? 'relevance-negative-control' : 'relevance', $result);
if ($metrics['precision_at_k'] < $dataset['thresholds']['precision_at_k']
    || $metrics['recall_at_k'] < $dataset['thresholds']['recall_at_k']
    || $metrics['reciprocal_rank'] < $dataset['thresholds']['reciprocal_rank']) {
    fwrite(STDERR, "Relevance thresholds not met.\n");
    $exitCode = 1;
}
exit($exitCode);
