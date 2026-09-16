<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationRunAuditEvent extends CatalogueModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /**
     * @return BelongsTo<OperationRun, $this>
     */
    public function operationRun(): BelongsTo
    {
        return $this->belongsTo(OperationRun::class);
    }
}
