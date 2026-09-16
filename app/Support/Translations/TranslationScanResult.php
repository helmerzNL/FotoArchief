<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * Everything one source scan found: the literal keys, the calls whose key is
 * built at runtime, and how many files were read.
 */
final readonly class TranslationScanResult
{
    /**
     * @param  list<TranslationReference>  $references
     * @param  list<DynamicTranslationReference>  $dynamicReferences
     * @param  list<string>  $scannedFiles
     */
    public function __construct(
        public array $references,
        public array $dynamicReferences,
        public array $scannedFiles,
    ) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->references as $reference) {
            $keys[$reference->key] = true;
        }

        $unique = array_keys($keys);
        sort($unique);

        return $unique;
    }
}
