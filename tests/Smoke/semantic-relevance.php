<?php

declare(strict_types=1);

use App\Modules\Ai\Support\SemanticRelevanceEvaluator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = getopt('', ['opt-in', 'corpus:', 'k:', 'min-precision:', 'min-recall:']);
if (! isset($arguments['opt-in'], $arguments['corpus'], $arguments['k'], $arguments['min-precision'], $arguments['min-recall'])) {
    fwrite(STDERR, "Usage: php tests/Smoke/semantic-relevance.php --opt-in --corpus=approved.json --k=N --min-precision=0..1 --min-recall=0..1\n");
    exit(2);
}
$k = filter_var($arguments['k'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$precision = filter_var($arguments['min-precision'], FILTER_VALIDATE_FLOAT);
$recall = filter_var($arguments['min-recall'], FILTER_VALIDATE_FLOAT);
if ($k === false || $precision === false || $recall === false || $precision < 0 || $precision > 1 || $recall < 0 || $recall > 1 || ! is_file($arguments['corpus'])) {
    fwrite(STDERR, "Corpus, k and thresholds must be explicit valid operator inputs.\n");
    exit(2);
}
$corpus = json_decode((string) file_get_contents($arguments['corpus']), true, 512, JSON_THROW_ON_ERROR);
$metrics = (new SemanticRelevanceEvaluator)->evaluate($corpus['queries'] ?? [], $k);
$metrics['k'] = $k;
$metrics['thresholds'] = ['min_precision' => $precision, 'min_recall' => $recall];
echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
if ($metrics['precision_at_k'] < $precision || $metrics['recall_at_k'] < $recall) {
    fwrite(STDERR, "Semantic relevance thresholds were not met.\n");
    exit(1);
}
