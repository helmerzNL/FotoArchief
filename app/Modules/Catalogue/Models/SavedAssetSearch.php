<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property array<string, string> $filters
 */
class SavedAssetSearch extends CatalogueModel
{
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
