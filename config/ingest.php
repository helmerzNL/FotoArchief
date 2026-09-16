<?php

declare(strict_types=1);

return [
    'max_upload_bytes' => (int) env('APP_MAX_UPLOAD_BYTES', 104_857_600),
    'max_image_pixels' => (int) env('APP_MAX_IMAGE_PIXELS', 80_000_000),
    'max_batch_upload_files' => (int) env('APP_MAX_BATCH_UPLOAD_FILES', 250),
    'scanner' => env('INGEST_SCANNER', 'none'),
    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
    'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    'clamav_timeout' => 30,
];
