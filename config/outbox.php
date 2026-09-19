<?php

declare(strict_types=1);

return [
    'connection' => env('OUTBOX_QUEUE_CONNECTION', 'ingest'),
    'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 60),
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
    'readiness_minutes' => (int) env('OUTBOX_READINESS_MINUTES', 10),
];
