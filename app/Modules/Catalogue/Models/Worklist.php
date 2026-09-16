<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worklist extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'filter_criteria' => 'array',
        ];
    }

    /**
     * @return HasMany<WorklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WorklistItem::class);
    }

    /**
     * @return BelongsToMany<Asset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'worklist_items')->withPivot(['id', 'status', 'completed_at', 'completed_by_user_id', 'note'])->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function totalCount(): int
    {
        return $this->items()->count();
    }

    public function completedCount(): int
    {
        return $this->items()->where('status', 'completed')->count();
    }

    public function progressPercentage(): int
    {
        $total = $this->totalCount();
        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->completedCount() / $total) * 100);
    }
}
