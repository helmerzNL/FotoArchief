<?php

declare(strict_types=1);

return [
    'default_locale' => env('APP_LOCALE', 'nl'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'nl'),
    'session_key' => 'localization.locale_preference',
    'supported_locales' => [
        'nl' => [
            'name' => 'Nederlands',
            'native_name' => 'Nederlands',
        ],
    ],
];
