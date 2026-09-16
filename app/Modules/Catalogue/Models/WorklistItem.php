<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorklistItem extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Worklist, $this>
     */
    public function worklist(): BelongsTo
    {
        return $this->belongsTo(Worklist::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
