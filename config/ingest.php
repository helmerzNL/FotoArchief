<?php

declare(strict_types=1);

return [
    'max_upload_bytes' => (int) env('APP_MAX_UPLOAD_BYTES', 104_857_600),
    'max_image_pixels' => (int) env('APP_MAX_IMAGE_PIXELS', 80_000_000),
    'max_batch_upload_files' => (int) env('APP_MAX_BATCH_UPLOAD_FILES', 250),
    'pdf' => [
        'info_binary' => env('PDFINFO_BINARY', 'pdfinfo'),
        'render_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'timeout_seconds' => (int) env('PDF_RENDER_TIMEOUT', 60),
    ],
    // ingest:import-directory pacing for archives of hundreds of GB. The command stops
    // pausing after wait_seconds, and retries an object-store write after each delay (seconds).
    'import' => [
        'max_pending' => 200,
        'wait_seconds' => 600,
        'retry_delays' => [2, 10, 30],
    ],
    'scanner' => env('INGEST_SCANNER', 'none'),
    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
    'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    'clamav_timeout' => (int) env('CLAMAV_TIMEOUT', 30),
];
