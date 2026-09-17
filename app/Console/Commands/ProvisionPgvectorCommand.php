<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use Illuminate\Console\Command;

class ProvisionPgvectorCommand extends Command
{
    protected $signature = 'ai:provision-pgvector';

    public function getDescription(): string
    {
        return __('ai.pgvector_provision_description');
    }

    public function handle(PgvectorEmbeddingStore $vectors): int
    {
        $vectors->provision();
        $this->info(__('ai.pgvector_provisioned'));

        return self::SUCCESS;
    }
}
