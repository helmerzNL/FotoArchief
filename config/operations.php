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
    // In-app archive backup and restore (App-Guidance shared/guidelines/backup-restore.md).
    // The owner's own copy of the archive, not a replacement for the database dump and the
    // storage copy of docs/BACKUP_RESTORE.md.
    'backup' => [
        'staging_path' => storage_path('app/backup-staging'),
        // A restore upload arrives in 16 MiB chunks and is kept in staging until it is used.
        'max_upload_bytes' => (int) env('FOTOARCHIEF_BACKUP_UPLOAD_MAX_BYTES', 10_737_418_240),
        'max_document_bytes' => 536_870_912,
        // Work per queue step; the worker stops a job after 120 seconds.
        'step_seconds' => 60,
        'stale_minutes' => 15,
        'auto' => [
            'enabled' => filter_var(env('FOTOARCHIEF_AUTO_BACKUP', false), FILTER_VALIDATE_BOOL),
            'interval_hours' => max(1, (int) env('FOTOARCHIEF_AUTO_BACKUP_HOURS', 12)),
            'keep' => max(1, (int) env('FOTOARCHIEF_AUTO_BACKUP_KEEP', 14)),
            'with_files' => filter_var(env('FOTOARCHIEF_AUTO_BACKUP_FILES', true), FILTER_VALIDATE_BOOL),
            'path' => storage_path('app/backups/auto'),
            'retry_minutes' => 60,
        ],
    ],
    'alerts' => [
        'enabled' => filter_var(env('OPERATIONS_ALERTS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'webhook_url' => env('OPERATIONS_ALERT_WEBHOOK_URL'),
        'minimum_severity' => env('OPERATIONS_ALERT_MINIMUM_SEVERITY', 'warning'),
        'failed_ingest_threshold' => (int) env('OPERATIONS_ALERT_FAILED_INGEST_THRESHOLD', 5),
        'pending_ingest_threshold' => (int) env('OPERATIONS_ALERT_PENDING_INGEST_THRESHOLD', 100),
    ],
];
