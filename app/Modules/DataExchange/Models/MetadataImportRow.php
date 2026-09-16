<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Models;

use App\Modules\Catalogue\Models\Asset;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $mapped_values
 */
class MetadataImportRow extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'expected_lock_version' => 'integer',
            'mapped_values' => 'array',
            'changes' => 'array',
            'messages' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mappedValues(): array
    {
        return $this->mapped_values ?? [];
    }

    /** @return BelongsTo<MetadataImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(MetadataImport::class, 'metadata_import_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
