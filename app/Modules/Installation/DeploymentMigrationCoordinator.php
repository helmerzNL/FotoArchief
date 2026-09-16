<?php

declare(strict_types=1);

namespace App\Modules\Installation;

interface DeploymentMigrationCoordinator
{
    public function migrate(): void;
}
