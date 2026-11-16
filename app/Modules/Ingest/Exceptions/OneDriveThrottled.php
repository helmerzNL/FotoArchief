<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use RuntimeException;

/** Microsoft Graph asked to wait (429 or 503); retry after the given number of seconds. */
final class OneDriveThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(__('operations.onedrive.errors.throttled', ['seconds' => $retryAfterSeconds]));
    }
}
