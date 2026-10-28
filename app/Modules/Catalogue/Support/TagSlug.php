<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Support;

use Illuminate\Support\Str;

/**
 * The one way a tag name becomes a slug. Tags are matched on this slug, so every
 * writer (staff forms, bulk edits, AI suggestions) must use it; two spellings of
 * one word ("Kerk", "kerk ") then land on one row instead of two.
 */
final class TagSlug
{
    public static function for(string $name): string
    {
        $slug = Str::slug(trim($name));

        // A name without letters or digits (for example "!!!") has no readable slug;
        // a stable hash keeps it unique instead of colliding on an empty string.
        return $slug !== '' ? $slug : 't-'.substr(hash('sha256', $name), 0, 12);
    }
}
