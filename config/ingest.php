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
    // TIFF and HEIC previews come from libvips (libvips-tools in the runtime image).
    'raster' => [
        'vips_binary' => env('VIPS_BINARY', 'vips'),
        'vipsheader_binary' => env('VIPSHEADER_BINARY', 'vipsheader'),
        'timeout_seconds' => (int) env('RASTER_CONVERT_TIMEOUT', 60),
    ],
    // ingest:import-directory pacing for archives of hundreds of GB. The command stops
    // pausing after wait_seconds, and retries an object-store write after each delay (seconds).
    'import' => [
        'max_pending' => 200,
        'wait_seconds' => 600,
        'retry_delays' => [2, 10, 30],
    ],
    // Read-only import from a personal OneDrive (ADR-0022/ADR-0023 in App-Guidance). The
    // administrator connects with a code (device code flow) through one shared, public
    // app registration: no secret, no redirect URL. An administrator can enter an own
    // client ID in the admin screen instead; that one wins over this default.
    'onedrive' => [
        // The client ID of the shared Vistora app registration (public, not a secret).
        'client_id' => '',
        'authority' => 'https://login.microsoftonline.com/consumers/oauth2/v2.0',
        'graph' => 'https://graph.microsoft.com/v1.0',
        'scopes' => 'Files.Read offline_access',
        'timeout_seconds' => 30,
        // Microsoft's own polling interval wins when it is longer.
        'device_poll_min_seconds' => 5,
        'files_per_chunk' => 25,
        // A chunk starts no new download after this many seconds and gives each
        // download at most the rest of chunk_seconds, so a chunk ends well inside the
        // 120 s job timeout.
        'start_downloads_until_seconds' => 60,
        'chunk_seconds' => 100,
        'max_pending' => 200,
        'backlog_wait_seconds' => 60,
        'max_retry_after_seconds' => 900,
    ],
    'scanner' => env('INGEST_SCANNER', 'none'),
    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
    'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    'clamav_timeout' => (int) env('CLAMAV_TIMEOUT', 30),
];
