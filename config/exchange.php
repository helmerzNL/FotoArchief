<?php

declare(strict_types=1);

return [
    'max_import_bytes' => (int) env('EXCHANGE_MAX_IMPORT_BYTES', 5_242_880),
    'max_import_rows' => (int) env('EXCHANGE_MAX_IMPORT_ROWS', 5_000),
    'max_import_columns' => 60,
    'max_cell_characters' => 10_000,
    'sync_analysis_bytes' => (int) env('EXCHANGE_SYNC_ANALYSIS_BYTES', 262_144),
    'preview_rows' => 200,
];
