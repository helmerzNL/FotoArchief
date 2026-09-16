<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;

/**
 * @property string $role
 * @property string $state
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SystemHeartbeat extends CatalogueModel
{
    protected $primaryKey = 'role';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
        'last_seen_at' => 'immutable_datetime',
    ];
}
