<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * Compares the translation keys the source uses against the keys the locale
 * catalogues define, and refuses everything it cannot prove.
 *
 * The check is fail-closed on four counts, and each of them exists because the
 * opposite default hides a real defect until a visitor meets it:
 *
 * - a referenced key that no catalogue defines fails, because Laravel renders
 *   the raw key to the page instead of erroring;
 * - a catalogue key nothing references fails, because a renamed key leaves the
 *   old text behind and the next translator keeps maintaining dead strings;
 * - a key built at runtime fails unless an allowlist entry names the file and
 *   the patterns it can produce, because the scanner cannot verify it;
 * - an allowlist entry that no longer matches anything fails, so the escape
 *   hatch cannot outlive the code it was written for.
 */
final class TranslationCatalogueChecker
{
    public function check(TranslationCheckConfiguration $configuration): TranslationCheckReport
    {
        $problems = [];

        $scanner = new TranslationScanner($configuration->basePath);
        $scan = $scanner->scan($configuration->scanPaths, $configuration->scanExtensions);

        $catalogues = [];
        foreach ($configuration->locales as $locale) {
            $catalogue = TranslationCatalogue::load($configuration->langPath, $locale);

            if (! $catalogue->exists) {
                $problems[] = new TranslationProblem(
                    TranslationProblem::MISSING_CATALOGUE,
                    'lang/'.$locale,
                    'Catalogus ontbreekt voor locale ['.$locale.'] / catalogue missing for locale ['.$locale.']',
                );

                continue;
            }

            $catalogues[$locale] = $catalogue;
        }

        [$dynamicProblems, $usedAllowlistIndexes, $allowedPatterns] = $this->checkDynamicReferences(
            $scan->dynamicReferences,
            $configuration->dynamicAllowlist,
        );
        $problems = array_merge($problems, $dynamicProblems);

        $problems = array_merge($problems, $this->checkMissingKeys($scan->references, $catalogues));
        $problems = array_merge($problems, $this->checkParity($catalogues));

        [$unusedProblems, $usedUnusedPatterns] = $this->checkUnusedKeys(
            $catalogues,
            $scan->keys(),
            $allowedPatterns,
            $configuration->unusedAllowlist,
        );
        $problems = array_merge($problems, $unusedProblems);

        $problems = array_merge($problems, $this->checkStaleAllowlist(
            $configuration,
            $usedAllowlistIndexes,
            $usedUnusedPatterns,
        ));

        $sizes = [];
        foreach ($catalogues as $locale => $catalogue) {
            $sizes[$locale] = $catalogue->count();
        }

        return new TranslationCheckReport(
            array_values($problems),
            count($scan->scannedFiles),
            count($scan->keys()),
            $sizes,
        );
    }

    /**
     * @param  list<DynamicTranslationReference>  $dynamicReferences
     * @param  list<array{file: string, keys: list<string>, reason: string}>  $allowlist
     * @return array{0: list<TranslationProblem>, 1: list<int>, 2: list<string>}
     */
    private function checkDynamicReferences(array $dynamicReferences, array $allowlist): array
    {
        $problems = [];
        $usedIndexes = [];
        $allowedPatterns = [];

        foreach ($dynamicReferences as $reference) {
            $matchedIndex = null;

            foreach ($allowlist as $index => $entry) {
                if ($this->matchesPattern($reference->file, $entry['file'])) {
                    $matchedIndex = $index;

                    break;
                }
            }

            if ($matchedIndex === null) {
                $problems[] = new TranslationProblem(
                    TranslationProblem::DYNAMIC_KEY,
                    $reference->location(),
                    'Dynamische sleutel ['.$reference->expression.'] staat niet op de allowlist / dynamic key not allowlisted',
                );

                continue;
            }

            $usedIndexes[] = $matchedIndex;

            foreach ($allowlist[$matchedIndex]['keys'] as $pattern) {
                $allowedPatterns[] = $pattern;
            }
        }

        return [$problems, array_values(array_unique($usedIndexes)), array_values(array_unique($allowedPatterns))];
    }

    /**
     * @param  list<TranslationReference>  $references
     * @param  array<string, TranslationCatalogue>  $catalogues
     * @return list<TranslationProblem>
     */
    private function checkMissingKeys(array $references, array $catalogues): array
    {
        $problems = [];
        $seen = [];

        foreach ($references as $reference) {
            foreach ($catalogues as $locale => $catalogue) {
                if ($catalogue->has($reference->key)) {
                    continue;
                }

                $signature = $locale.'|'.$reference->key.'|'.$reference->location();
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;

                $problems[] = new TranslationProblem(
                    TranslationProblem::MISSING_KEY,
                    $reference->location(),
                    'Sleutel ['.$reference->key.'] ontbreekt in lang/'.$locale.' / key missing in lang/'.$locale,
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, TranslationCatalogue>  $catalogues
     * @return list<TranslationProblem>
     */
    private function checkParity(array $catalogues): array
    {
        if (count($catalogues) < 2) {
            return [];
        }

        $union = [];
        foreach ($catalogues as $catalogue) {
            foreach ($catalogue->keys() as $key) {
                $union[$key] = true;
            }
        }

        $allKeys = array_keys($union);
        sort($allKeys);

        $problems = [];

        foreach ($catalogues as $locale => $catalogue) {
            foreach ($allKeys as $key) {
                if ($catalogue->has($key)) {
                    continue;
                }

                $present = [];
                foreach ($catalogues as $otherLocale => $other) {
                    if ($other->has($key)) {
                        $present[] = $otherLocale;
                    }
                }

                $problems[] = new TranslationProblem(
                    TranslationProblem::PARITY_GAP,
                    'lang/'.$locale.' :: '.$key,
                    'Aanwezig in ['.implode(', ', $present).'] maar niet in ['.$locale.'] / present in other locales only',
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, TranslationCatalogue>  $catalogues
     * @param  list<string>  $referencedKeys
     * @param  list<string>  $allowedDynamicPatterns
     * @param  list<string>  $unusedAllowlist
     * @return array{0: list<TranslationProblem>, 1: list<string>}
     */
    private function checkUnusedKeys(array $catalogues, array $referencedKeys, array $allowedDynamicPatterns, array $unusedAllowlist): array
    {
        $referenced = array_fill_keys($referencedKeys, true);
        $problems = [];
        $usedPatterns = [];
        $reported = [];

        foreach ($catalogues as $locale => $catalogue) {
            foreach ($catalogue->keys() as $key) {
                if ($this->isReferenced($key, $referenced) || $this->matchesAny($key, $allowedDynamicPatterns)) {
                    continue;
                }

                $matchedPattern = $this->firstMatch($key, $unusedAllowlist);
                if ($matchedPattern !== null) {
                    $usedPatterns[] = $matchedPattern;

                    continue;
                }

                if (isset($reported[$key])) {
                    continue;
                }
                $reported[$key] = true;

                $problems[] = new TranslationProblem(
                    TranslationProblem::UNUSED_KEY,
                    $catalogue->sourceFor($key),
                    'Sleutel ['.$key.'] wordt nergens gebruikt / key is never referenced (locale '.$locale.')',
                );
            }
        }

        return [$problems, array_values(array_unique($usedPatterns))];
    }

    /**
     * A Laravel translation call may request an entire nested array. In that
     * case every leaf below the referenced parent is in use.
     *
     * @param  array<string, true>  $referenced
     */
    private function isReferenced(string $key, array $referenced): bool
    {
        if (isset($referenced[$key])) {
            return true;
        }

        foreach (array_keys($referenced) as $reference) {
            if (str_starts_with($key, $reference.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $usedDynamicIndexes
     * @param  list<string>  $usedUnusedPatterns
     * @return list<TranslationProblem>
     */
    private function checkStaleAllowlist(TranslationCheckConfiguration $configuration, array $usedDynamicIndexes, array $usedUnusedPatterns): array
    {
        $problems = [];

        foreach ($configuration->dynamicAllowlist as $index => $entry) {
            if (in_array($index, $usedDynamicIndexes, true)) {
                continue;
            }

            $problems[] = new TranslationProblem(
                TranslationProblem::STALE_ALLOWLIST,
                'config/translations.php :: allowlist.dynamic :: '.$entry['file'],
                'Geen dynamische aanroep meer gevonden; verwijder de uitzondering / no dynamic call left, remove the exception',
            );
        }

        foreach ($configuration->unusedAllowlist as $pattern) {
            if (in_array($pattern, $usedUnusedPatterns, true)) {
                continue;
            }

            $problems[] = new TranslationProblem(
                TranslationProblem::STALE_ALLOWLIST,
                'config/translations.php :: allowlist.unused :: '.$pattern,
                'Patroon dekt geen enkele sleutel meer; verwijder de uitzondering / pattern matches nothing, remove the exception',
            );
        }

        return $problems;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $value, array $patterns): bool
    {
        return $this->firstMatch($value, $patterns) !== null;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function firstMatch(string $value, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($value, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        if ($pattern === $value) {
            return true;
        }

        if (! str_contains($pattern, '*')) {
            return false;
        }

        $quoted = preg_quote($pattern, '/');
        $regex = '/^'.str_replace('\*', '.*', $quoted).'$/';

        return preg_match($regex, $value) === 1;
    }
}
