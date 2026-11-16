<?php

declare(strict_types=1);

return [
    // Update check (App-Guidance shared/guidelines/update-check.md): reads the latest stable
    // GitHub release and tells administrators; it never updates anything. Off with
    // FOTOARCHIEF_UPDATE_CHECK=false (0, no and off count as well).
    'updates' => [
        'enabled' => ! in_array(strtolower(trim((string) env('FOTOARCHIEF_UPDATE_CHECK', 'true'))), ['false', '0', 'no', 'off'], true),
        'repository' => 'helmerzNL/FotoArchief',
        // How this installation is deployed, for the update steps shown: compose, komodo,
        // dockhand or webhosting. Empty: compose inside a container, webhosting otherwise.
        'deployment' => env('FOTOARCHIEF_DEPLOYMENT'),
        'timeout_seconds' => 5,
        'success_ttl_seconds' => 21600,
        'failure_ttl_seconds' => 600,
        'manual_floor_seconds' => 30,
    ],
    'alerts' => [
        'enabled' => filter_var(env('OPERATIONS_ALERTS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'webhook_url' => env('OPERATIONS_ALERT_WEBHOOK_URL'),
        'minimum_severity' => env('OPERATIONS_ALERT_MINIMUM_SEVERITY', 'warning'),
        'failed_ingest_threshold' => (int) env('OPERATIONS_ALERT_FAILED_INGEST_THRESHOLD', 5),
        'pending_ingest_threshold' => (int) env('OPERATIONS_ALERT_PENDING_INGEST_THRESHOLD', 100),
    ],
];
