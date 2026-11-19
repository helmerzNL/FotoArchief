<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use RuntimeException;

/** The administrator has not finished signing in with the code yet; ask again later. */
final class OneDriveDevicePending extends RuntimeException
{
    public function __construct(public readonly bool $slowDown)
    {
        parent::__construct('pending');
    }
}
