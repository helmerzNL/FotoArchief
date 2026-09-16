<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingJob extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    /**
     * @return BelongsTo<AssetFile, $this>
     */
    public function assetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class);
    }
}
