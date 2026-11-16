<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use RuntimeException;

/** The stored delta link is no longer valid (HTTP 410); the folder must be read again in full. */
final class OneDriveDeltaExpired extends RuntimeException {}
