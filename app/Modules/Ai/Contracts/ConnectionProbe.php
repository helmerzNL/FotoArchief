<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * A cheap, non-billable (or effectively free) connectivity/capability check
 * used by the settings UI's connection test. Implementations MUST NOT
 * perform an actual image-analysis or embedding call: this is strictly a
 * config/API-key/model-listing check so an administrator can validate
 * setup without incurring provider cost. The separate "paid image proof"
 * connection test (a real analyzeImage()/embedImage() call) is gated
 * behind explicit one-off operator consent and does not use this contract.
 */
interface ConnectionProbe
{
    /**
     * @return array<string, mixed> provider-specific payload; native
     *                              adapters return at least a 'models'
     *                              list<string> of model IDs visible to
     *                              the configured API key.
     */
    public function probe(): array;
}
