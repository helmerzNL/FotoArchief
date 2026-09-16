<?php

declare(strict_types=1);

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
    ],
    'tesseract' => [
        'enabled' => (bool) env('OCR_ENABLED', false),
        'binary' => env('OCR_BINARY', 'tesseract'),
        'languages' => env('OCR_LANGUAGES', 'nld+eng'),
        'timeout' => (int) env('OCR_TIMEOUT', 60),
    ],
];
