<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * One literal translation key found in the source, with the place that uses it.
 *
 * The location is what makes a failure actionable: a key name alone sends the
 * reader searching, while a file and line send them to the call site.
 */
final readonly class TranslationReference
{
    public function __construct(
        public string $key,
        public string $file,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}
