<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use RuntimeException;

/** The TIFF or HEIC file itself is unusable (corrupt, truncated, undecodable); retrying cannot help. */
final class UnreadableRasterImage extends RuntimeException {}
