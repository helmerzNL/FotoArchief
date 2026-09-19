<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageTombstone extends CatalogueModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'retained_until' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StorageMigration, $this> */
    public function migration(): BelongsTo
    {
        return $this->belongsTo(StorageMigration::class, 'storage_migration_id');
    }

    /** @return BelongsTo<StorageRelocation, $this> */
    public function relocation(): BelongsTo
    {
        return $this->belongsTo(StorageRelocation::class, 'storage_relocation_id');
    }
}
