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
];
