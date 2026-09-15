<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallationDatabase
{
    public function connect(InstallationSettings $settings): void
    {
        $settings->apply(config());
        DB::purge('pgsql');
        DB::connection()->select('SELECT 1');
    }

    public function requireEmptyDatabase(): void
    {
        if (Schema::getTables() !== []) {
            throw new InstallationFailure('Deze database bevat al tabellen. Gebruik een lege PostgreSQL-database voor een nieuwe installatie.');
        }
    }
}
