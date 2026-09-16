<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * A translation call whose key is not a literal, for example __($key).
 *
 * The scanner cannot know which keys such a call can produce, so the checker
 * treats every one of them as a failure until an explicit allowlist entry names
 * the file, the key patterns it can build and the reason.
 */
final readonly class DynamicTranslationReference
{
    public function __construct(
        public string $expression,
        public string $file,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}
