<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Exceptions;

use RuntimeException;

/**
 * A run cannot continue until a person acts (for example: reconnect an account).
 * Retrying the job would only repeat the same failure, so the run fails at once
 * with this message and keeps its cursor for a later retry.
 */
class PermanentOperationFailure extends RuntimeException {}
