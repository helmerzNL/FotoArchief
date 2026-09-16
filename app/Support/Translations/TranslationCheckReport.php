<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * The outcome of one check run: the findings plus the counts an operator needs
 * to see that the check actually looked at something.
 *
 * A green check that scanned nothing is indistinguishable from a green check
 * that scanned everything, which is why the counts are part of the report.
 */
final readonly class TranslationCheckReport
{
    /**
     * @param  list<TranslationProblem>  $problems
     * @param  array<string, int>  $catalogueSizes
     */
    public function __construct(
        public array $problems,
        public int $scannedFileCount,
        public int $referencedKeyCount,
        public array $catalogueSizes,
    ) {}

    public function passed(): bool
    {
        return $this->problems === [];
    }

    /**
     * @return list<TranslationProblem>
     */
    public function problemsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->problems,
            static fn (TranslationProblem $problem): bool => $problem->type === $type,
        ));
    }

    /**
     * @return array<string, list<TranslationProblem>>
     */
    public function groupedProblems(): array
    {
        $grouped = [];

        foreach ($this->problems as $problem) {
            $grouped[$problem->type][] = $problem;
        }

        return $grouped;
    }
}
