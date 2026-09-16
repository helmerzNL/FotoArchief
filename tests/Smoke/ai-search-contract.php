<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'service' => file_get_contents($root.'/app/Modules/Ai/Services/AiSemanticSearchService.php'),
    'public_controller' => file_get_contents($root.'/app/Http/Controllers/Publication/PublicDiscoveryController.php'),
    'public_view' => file_get_contents($root.'/resources/views/public/discover.blade.php'),
    'admin_view' => file_get_contents($root.'/resources/views/ai/search/admin.blade.php'),
    'decision' => file_get_contents($root.'/docs/AI_CAPABILITY_DECISION.md'),
];

foreach ($files as $name => $contents) {
    if (! is_string($contents) || $contents === '') {
        fwrite(STDERR, "Missing readable AI search contract file: {$name}\n");
        exit(1);
    }
}

$required = [
    'same multimodal text/image model space' => str_contains($files['service'], "where('model_space', \$queryEmbedding['model_space'])"),
    'bounded candidate window' => str_contains($files['service'], '$candidateLimit = 500'),
    'admin policy filtering' => str_contains($files['service'], "Gate::forUser(\$user)->allows('view', \$asset)"),
    'public visibility predicate' => str_contains($files['service'], '->publiclyVisible()'),
    'public semantic label' => str_contains($files['public_view'], '<label for="semantic_q">Zoeken op beeldinhoud</label>'),
    'public error role' => str_contains($files['public_view'], 'role="alert"'),
    'admin semantic label' => str_contains($files['admin_view'], '<label>Zoekvraag'),
    'caption-only rejection documented' => str_contains($files['decision'], 'caption-only retrieval is still'),
];

foreach ($required as $description => $passed) {
    if (! $passed) {
        fwrite(STDERR, "AI search contract failed: {$description}\n");
        exit(1);
    }
}

echo "AI search contract smoke passed.\n";
