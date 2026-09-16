<?php

declare(strict_types=1);

return [
    'defaults' => [
        'global_enabled' => false,
        'emergency_stop' => false,
        'image_analysis_enabled' => false,
        'embeddings_enabled' => false,
        'local_provider_enabled' => false,
        'external_provider_enabled' => false,
        'external_processing_allowed' => false,
        'local_endpoint' => env('AI_LOCAL_ENDPOINT'),
        'external_endpoint' => env('AI_EXTERNAL_ENDPOINT'),
        'provider_region' => env('AI_PROVIDER_REGION'),
        'retention_notice' => env('AI_RETENTION_NOTICE'),
        'max_assets_per_batch' => 25,
        'derivative_max_pixels' => 1024,
        'request_timeout_seconds' => 60,
        'monthly_external_budget_cents' => 0,
    ],
];
