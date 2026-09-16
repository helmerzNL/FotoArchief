<?php

declare(strict_types=1);

namespace App\Support\Translations;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The flattened key set of one locale: lang/<locale>/*.php plus lang/<locale>.json.
 *
 * Keys are stored with the file they came from, because "this key is unused"
 * without the file it lives in is not a finding an editor can act on.
 */
final readonly class TranslationCatalogue
{
    /**
     * @param  array<string, string>  $keys  key => relative source file
     */
    private function __construct(
        public string $locale,
        public bool $exists,
        private array $keys,
    ) {}

    public static function load(string $langPath, string $locale): self
    {
        $directory = $langPath.DIRECTORY_SEPARATOR.$locale;
        $jsonFile = $langPath.DIRECTORY_SEPARATOR.$locale.'.json';
        $exists = is_dir($directory) || is_file($jsonFile);

        $keys = [];

        if (is_dir($directory)) {
            foreach (self::phpFiles($directory) as $file) {
                $group = self::groupName($directory, $file);
                $loaded = require $file;

                if (! is_array($loaded)) {
                    continue;
                }

                foreach (self::flatten($loaded, $group.'.') as $key) {
                    $keys[$key] = self::relative($langPath, $file);
                }
            }
        }

        if (is_file($jsonFile)) {
            $decoded = json_decode((string) file_get_contents($jsonFile), true);

            if (is_array($decoded)) {
                foreach (self::flatten($decoded, '') as $key) {
                    $keys[$key] = self::relative($langPath, $jsonFile);
                }
            }
        }

        ksort($keys);

        return new self($locale, $exists, $keys);
    }

    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->keys)) {
            return true;
        }

        $prefix = $key.'.';
        foreach (array_keys($this->keys) as $candidate) {
            if (str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    public function sourceFor(string $key): string
    {
        return $this->keys[$key] ?? 'lang/'.$this->locale;
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Laravel addresses nested catalogue files as "subdirectory/file.key".
     */
    private static function groupName(string $directory, string $file): string
    {
        $relative = substr($file, strlen($directory) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($relative, 0, -4));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function flatten(array $values, string $prefix): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $flatKey = $prefix.$key;

            if (is_array($value) && $value !== []) {
                foreach (self::flatten($value, $flatKey.'.') as $nested) {
                    $keys[] = $nested;
                }

                continue;
            }

            $keys[] = $flatKey;
        }

        return $keys;
    }

    private static function relative(string $langPath, string $file): string
    {
        $base = dirname($langPath);
        $relative = str_starts_with($file, $base) ? substr($file, strlen($base) + 1) : $file;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
