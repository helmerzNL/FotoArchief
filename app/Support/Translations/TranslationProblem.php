<?php

declare(strict_types=1);

namespace App\Support\Translations;

/**
 * One actionable finding: what is wrong, where, and which key it concerns.
 */
final readonly class TranslationProblem
{
    public const MISSING_CATALOGUE = 'missing_catalogue';

    public const MISSING_KEY = 'missing_key';

    public const UNUSED_KEY = 'unused_key';

    public const PARITY_GAP = 'parity_gap';

    public const DYNAMIC_KEY = 'dynamic_key';

    public const STALE_ALLOWLIST = 'stale_allowlist';

    public function __construct(
        public string $type,
        public string $subject,
        public string $detail,
    ) {}

    public function line(): string
    {
        return $this->detail === '' ? $this->subject : $this->subject.'  ->  '.$this->detail;
    }
}
