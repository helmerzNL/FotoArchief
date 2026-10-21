<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use RuntimeException;

/** The PDF itself is unusable (corrupt, encrypted, empty); retrying cannot help. */
final class UnreadablePdf extends RuntimeException {}
