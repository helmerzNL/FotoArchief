<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use RuntimeException;

/** The image data itself is unusable (corrupt, unsupported, too large to decode safely); retrying cannot help. */
final class UnreadableImage extends RuntimeException {}
