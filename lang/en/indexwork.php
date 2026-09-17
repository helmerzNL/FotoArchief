<?php

declare(strict_types=1);

return [
    'title' => 'Manage search index', 'coverage' => 'Index coverage', 'space' => 'Provider / requested model / active model space',
    'current' => 'Current', 'stale' => 'Stale', 'missing' => 'Missing', 'excluded' => 'Excluded', 'failed' => 'Failed',
    'coverage_notice' => 'Only clean, processed primary files are eligible. Current means a usable vector in the active generation for the configured model. Missing/stale items support targeted repair; failed items can be retried through task details.',
    'select' => 'Select for repair',
    'confirm' => 'I confirm processing this selection within the chosen collection using the configured provider. Provider costs may apply.',
    'repair' => 'Repair selected index items',
    'changed' => 'The active generation or model configuration changed. Refresh before starting repair.',
    'selection_changed' => 'The selection does not contain only missing or stale items from this collection.',
    'generations' => 'Generations and activation', 'activate' => 'Retry activation',
    'confirm_activation' => 'I confirm activation; source and generation checks remain mandatory.',
    'activated' => 'Generation safely activated.',
    'mode' => 'Search method', 'text' => 'Catalogue metadata text', 'semantic' => 'Semantic similarity',
    'search_notice' => 'Text search uses stored metadata without an AI request. Semantic search uses only the configured provider and active model space. No automatic fallback to an external service.',
    'empty' => 'No results for this query and collection. Adjust filters or explicitly try text search.',
    'not_searched' => 'Enter a query to search.',
    'label' => 'Rate relevance', 'irrelevant' => 'Not relevant', 'partial' => 'Partly relevant', 'relevant' => 'Relevant',
    'label_saved' => 'Your relevance assessment was saved.',
    'invalid_receipt' => 'This search result expired or is invalid. Search again before rating.',
    'export_labels' => 'Export my relevance labels (JSONL)',
    'labels_notice' => 'Export contains your queries, photo ID, model space and rating. This is an internal assessment set, not objective quality evidence.',
];
