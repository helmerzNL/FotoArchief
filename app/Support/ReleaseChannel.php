<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The release channel an installation runs, derived from the image tag it was started with
 * (FOTOARCHIEF_TAG in Compose): a tag that contains `beta` is the beta channel, anything else
 * (latest, a version, a local build) is stable. App-Guidance project-baseline.md section 20.
 */
final class ReleaseChannel
{
    public const string BETA = 'beta';

    public const string STABLE = 'stable';

    public static function forTag(mixed $tag): string
    {
        return is_string($tag) && str_contains(strtolower($tag), 'beta') ? self::BETA : self::STABLE;
    }
}
