<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * The resolved input of one check run.
 *
 * Keeping paths and allowlists in a value object instead of reading config()
 * inside the checker is what makes the failing fixtures in the test suite
 * possible: a test points the same code at a fixture tree.
 */
final readonly class TranslationCheckConfiguration
{
    /**
     * @param  list<string>  $locales
     * @param  list<string>  $scanPaths
     * @param  list<string>  $scanExtensions
     * @param  list<array{file: string, keys: list<string>, reason: string}>  $dynamicAllowlist
     * @param  list<string>  $unusedAllowlist
     */
    public function __construct(
        public string $basePath,
        public string $langPath,
        public array $locales,
        public string $referenceLocale,
        public array $scanPaths,
        public array $scanExtensions,
        public array $dynamicAllowlist,
        public array $unusedAllowlist,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(array $config, string $basePath, string $langPath): self
    {
        $locales = self::stringList($config['locales'] ?? []);
        $reference = is_string($config['reference_locale'] ?? null) ? $config['reference_locale'] : ($locales[0] ?? 'nl');
        $allowlist = is_array($config['allowlist'] ?? null) ? $config['allowlist'] : [];

        return new self(
            basePath: rtrim($basePath, DIRECTORY_SEPARATOR.'/'),
            langPath: rtrim($langPath, DIRECTORY_SEPARATOR.'/'),
            locales: $locales,
            referenceLocale: $reference,
            scanPaths: self::stringList($config['scan_paths'] ?? []),
            scanExtensions: self::stringList($config['scan_extensions'] ?? ['php']),
            dynamicAllowlist: self::dynamicEntries(is_array($allowlist['dynamic'] ?? null) ? $allowlist['dynamic'] : []),
            unusedAllowlist: self::stringList(is_array($allowlist['unused'] ?? null) ? $allowlist['unused'] : []),
        );
    }

    /**
     * @param  list<string>  $locales
     */
    public function withLocales(array $locales): self
    {
        return new self(
            $this->basePath,
            $this->langPath,
            $locales,
            in_array($this->referenceLocale, $locales, true) ? $this->referenceLocale : ($locales[0] ?? $this->referenceLocale),
            $this->scanPaths,
            $this->scanExtensions,
            $this->dynamicAllowlist,
            $this->unusedAllowlist,
        );
    }

    public function withPaths(string $basePath, string $langPath): self
    {
        return new self(
            rtrim($basePath, DIRECTORY_SEPARATOR.'/'),
            rtrim($langPath, DIRECTORY_SEPARATOR.'/'),
            $this->locales,
            $this->referenceLocale,
            $this->scanPaths,
            $this->scanExtensions,
            $this->dynamicAllowlist,
            $this->unusedAllowlist,
        );
    }

    /**
     * @param  list<string>  $scanPaths
     */
    public function withScanPaths(array $scanPaths): self
    {
        return new self(
            $this->basePath,
            $this->langPath,
            $this->locales,
            $this->referenceLocale,
            $scanPaths,
            $this->scanExtensions,
            $this->dynamicAllowlist,
            $this->unusedAllowlist,
        );
    }

    public function withoutAllowlists(): self
    {
        return new self(
            $this->basePath,
            $this->langPath,
            $this->locales,
            $this->referenceLocale,
            $this->scanPaths,
            $this->scanExtensions,
            [],
            [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return list<array{file: string, keys: list<string>, reason: string}>
     */
    private static function dynamicEntries(array $entries): array
    {
        $normalised = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
                continue;
            }

            $normalised[] = [
                'file' => str_replace(DIRECTORY_SEPARATOR, '/', $entry['file']),
                'keys' => self::stringList($entry['keys'] ?? []),
                'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : '',
            ];
        }

        return $normalised;
    }
}
