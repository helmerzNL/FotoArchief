<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

class PasskeyData
{
    /**
     * @param  array<int, string>|null  $transports
     */
    public function __construct(
        public readonly string $credentialId,
        public readonly string $credentialPublicKey,
        public readonly ?int $signatureCounter,
        public readonly ?array $transports = null,
    ) {}
}
