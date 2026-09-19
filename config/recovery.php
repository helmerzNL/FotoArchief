<?php

declare(strict_types=1);

return [
    'offsite_disk' => env('BACKUP_OFFSITE_DISK'),
    'automatic_restore' => [
        'enabled' => (bool) env('AUTOMATIC_RESTORE_DRILL_ENABLED', false),
        'parent_directory' => env('AUTOMATIC_RESTORE_DRILL_PARENT'),
    ],
    'storage_protection' => [
        'tombstone_retention_days' => (int) env('STORAGE_TOMBSTONE_RETENTION_DAYS', 30),
    ],
];
