<?php

declare(strict_types=1);

return [
    'max_import_bytes' => (int) env('EXCHANGE_MAX_IMPORT_BYTES', 5_242_880),
    'max_import_rows' => (int) env('EXCHANGE_MAX_IMPORT_ROWS', 5_000),
    'max_import_columns' => 60,
    'max_cell_characters' => 10_000,
    'sync_analysis_bytes' => (int) env('EXCHANGE_SYNC_ANALYSIS_BYTES', 262_144),
    'preview_rows' => 200,
    'max_export_assets' => (int) env('EXCHANGE_MAX_EXPORT_ASSETS', 500),
    'max_export_bytes' => (int) env('EXCHANGE_MAX_EXPORT_BYTES', 1_073_741_824),
    'export_ttl_minutes' => (int) env('EXCHANGE_EXPORT_TTL_MINUTES', 120),
    'download_ttl_minutes' => (int) env('EXCHANGE_DOWNLOAD_TTL_MINUTES', 10),

    // A worker that is killed or times out leaves its row claimed. Recovery
    // depends on three windows lining up, so they are configured together:
    //
    //     job_timeout_seconds  <  stale_claim_seconds  <  queue retry_after
    //             120                     150                    180
    //
    // The default matches the worker timeout the deploy files ship
    // (APP_WORKER_JOB_TIMEOUT_SECONDS=120); it is set on the job as well so the
    // application knows its own kill deadline instead of guessing it.
    //
    // The job timeout is the only thing that proves a claim holder is gone: the
    // worker kills the job at that point. The reclaim window sits above it, so
    // a live job is never stolen, and below retry_after, so the redelivered job
    // -- which arrives just under retry_after seconds after the claim -- still
    // qualifies to take the run over. A reclaim window above retry_after looks
    // safer and is not: the one redelivery arrives too early, finds the row
    // claimed, does nothing, and the run stays busy for ever. DataExportTest
    // asserts this ordering, so it cannot quietly drift back.
    'job_timeout_seconds' => (int) env('EXCHANGE_JOB_TIMEOUT_SECONDS', 120),
    'stale_claim_seconds' => (int) env('EXCHANGE_STALE_CLAIM_SECONDS', 150),
    'abandoned_claim_seconds' => (int) env('EXCHANGE_ABANDONED_CLAIM_SECONDS', 1800),
];
