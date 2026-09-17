<?php

declare(strict_types=1);

namespace App\Support\Translations;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds translation keys in PHP and Blade sources.
 *
 * Reading the first argument of every translation call is deliberate: a plain
 * regular expression over quoted strings would silently miss __($key) and
 * __("photo.{$state}"), and those are exactly the calls that break a catalogue
 * without anything noticing. The scanner reports them separately instead, so the
 * checker can refuse them unless they are allowlisted.
 */
final class TranslationScanner
{
    /**
     * Helper calls: the __, trans and trans_choice helpers plus the Blade lang
     * directive. The lookbehinds keep method calls such as $this->trans() and
     * Formatter::trans() out, while the directive is matched on its own because
     * it legitimately follows markup characters.
     */
    private const HELPER_PATTERN = '/(?:(?<![\w$\\\\])(?<!->)(?<!::)(?:__|trans_choice|trans)|@lang)\s*\(/';

    private const FACADE_PATTERN = '/(?<![\w$>])\\\\?Lang::(?:get|choice|has|hasForLocale)\s*\(/';

    private const INSTALLATION_TEXT_PATTERN = '/(?<![\w$>])\\\\?InstallationText::get\s*\(/';

    public function __construct(private readonly string $basePath) {}

    /**
     * @param  list<string>  $relativePaths
     * @param  list<string>  $extensions
     */
    public function scan(array $relativePaths, array $extensions = ['php']): TranslationScanResult
    {
        $references = [];
        $dynamic = [];
        $scanned = [];

        foreach ($this->collectFiles($relativePaths, $extensions) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $relative = $this->toRelativePath($file);
            $scanned[] = $relative;
            $searchable = $this->maskComments($file, $contents);

            foreach ($this->callOffsets($searchable) as $offset) {
                $argument = $this->readFirstArgument($searchable, $offset);
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $key = $this->literalValue($argument);

                if ($key === null) {
                    $dynamic[] = new DynamicTranslationReference(
                        $this->summarise($argument),
                        $relative,
                        $line,
                    );

                    continue;
                }

                $references[] = new TranslationReference($key, $relative, $line);
            }
        }

        sort($scanned);

        return new TranslationScanResult($references, $dynamic, $scanned);
    }

    /**
     * @param  list<string>  $relativePaths
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function collectFiles(array $relativePaths, array $extensions): array
    {
        $files = [];

        foreach ($relativePaths as $relativePath) {
            $absolute = $this->basePath.DIRECTORY_SEPARATOR.trim(str_replace('/', DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);

            if (is_file($absolute)) {
                $files[] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $item) {
                if (! $item->isFile()) {
                    continue;
                }

                $name = $item->getFilename();

                foreach ($extensions as $extension) {
                    if (str_ends_with($name, '.'.$extension)) {
                        $files[] = $item->getPathname();

                        break;
                    }
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<int> byte offsets of the opening parenthesis of every call
     */
    private function callOffsets(string $contents): array
    {
        $offsets = [];

        foreach ([self::HELPER_PATTERN, self::FACADE_PATTERN, self::INSTALLATION_TEXT_PATTERN] as $pattern) {
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $offsets[] = $match[1] + strlen($match[0]) - 1;
            }
        }

        $offsets = array_values(array_unique($offsets));
        sort($offsets);

        return $offsets;
    }

    /**
     * Reads the raw text of the first argument, respecting nesting and quotes.
     */
    private function readFirstArgument(string $contents, int $openParenthesis): string
    {
        $length = strlen($contents);
        $depth = 0;
        $quote = null;
        $argument = '';

        for ($index = $openParenthesis; $index < $length; $index++) {
            $character = $contents[$index];

            if ($quote !== null) {
                $argument .= $character;

                if ($character === '\\' && $index + 1 < $length) {
                    $argument .= $contents[$index + 1];
                    $index++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $argument .= $character;

                continue;
            }

            if ($character === '(' || $character === '[' || $character === '{') {
                $depth++;

                if ($depth > 1) {
                    $argument .= $character;
                }

                continue;
            }

            if ($character === ')' || $character === ']' || $character === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                $argument .= $character;

                continue;
            }

            if ($character === ',' && $depth === 1) {
                break;
            }

            $argument .= $character;
        }

        return trim($argument);
    }

    /**
     * Returns the key when the argument is a plain literal, or null when the key
     * is concatenated, interpolated or passed through a variable.
     */
    private function literalValue(string $argument): ?string
    {
        if ($argument === '') {
            return null;
        }

        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/s', $argument, $matches) === 1) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $matches[1]);
        }

        if (preg_match('/^"((?:[^"\\\\$]|\\\\.)*)"$/s', $argument, $matches) === 1) {
            return stripcslashes($matches[1]);
        }

        return null;
    }

    private function summarise(string $argument): string
    {
        $flat = preg_replace('/\s+/', ' ', $argument) ?? $argument;

        if ($flat === '') {
            return __('shared.generated.t_fb11bfeafc313ffa');
        }

        return mb_strlen($flat) > 80 ? mb_substr($flat, 0, 77).'...' : $flat;
    }

    /**
     * Replaces comment bodies with spaces so documentation examples such as the
     * one in config/translations.php are not read as real call sites. Byte
     * length is preserved, which keeps offsets and line numbers correct.
     */
    private function maskComments(string $file, string $contents): string
    {
        if (str_ends_with($file, '.blade.php')) {
            return $this->maskPattern($contents, '/\{\{--.*?--\}\}/s');
        }

        $masked = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                $masked .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? $this->blank($token[1])
                    : $token[1];

                continue;
            }

            $masked .= $token;
        }

        return $masked;
    }

    private function maskPattern(string $contents, string $pattern): string
    {
        $replaced = preg_replace_callback(
            $pattern,
            fn (array $matches): string => $this->blank($matches[0]),
            $contents,
        );

        return $replaced ?? $contents;
    }

    private function blank(string $text): string
    {
        $blanked = preg_replace('/[^\r\n]/', ' ', $text);

        return $blanked ?? $text;
    }

    private function toRelativePath(string $file): string
    {
        $relative = str_starts_with($file, $this->basePath)
            ? substr($file, strlen($this->basePath) + 1)
            : $file;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
