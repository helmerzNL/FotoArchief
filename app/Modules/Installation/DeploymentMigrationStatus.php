<?php

declare(strict_types=1);

namespace App\Modules\Installation;

final readonly class DeploymentMigrationStatus
{
    /**
     * @param  list<string>|null  $pendingMigrations
     */
    public function __construct(
        public bool $coordinatorActive,
        public ?array $pendingMigrations,
    ) {}

    public function ready(): bool
    {
        return ! $this->coordinatorActive && $this->pendingMigrations === [];
    }
}
