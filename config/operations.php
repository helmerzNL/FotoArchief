<?php

declare(strict_types=1);

return [
    'alerts' => [
        'enabled' => filter_var(env('OPERATIONS_ALERTS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'webhook_url' => env('OPERATIONS_ALERT_WEBHOOK_URL'),
        'minimum_severity' => env('OPERATIONS_ALERT_MINIMUM_SEVERITY', 'warning'),
        'failed_ingest_threshold' => (int) env('OPERATIONS_ALERT_FAILED_INGEST_THRESHOLD', 5),
        'pending_ingest_threshold' => (int) env('OPERATIONS_ALERT_PENDING_INGEST_THRESHOLD', 100),
    ],
];
