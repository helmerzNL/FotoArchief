<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Support;

use InvalidArgumentException;
use Normalizer;

/**
 * Normalises the folder path an original came from before it is stored as provenance.
 *
 * The result is always relative, uses "/" and is Unicode NFC, so the same folder
 * synced from Windows, macOS or OneDrive compares equal on a re-run.
 */
final class OriginalPath
{
    public const MAX_LENGTH = 1000;

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || ! mb_check_encoding($path, 'UTF-8')) {
            throw new InvalidArgumentException('Path contains control characters or invalid UTF-8.');
        }
        if (class_exists(Normalizer::class)) {
            $path = Normalizer::normalize($path, Normalizer::FORM_C) ?: $path;
        }
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        if ($segments === [] || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1 || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('Path must be relative and stay inside the import folder.');
        }
        $normalized = implode('/', $segments);
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Path is too long.');
        }

        return $normalized;
    }
}
