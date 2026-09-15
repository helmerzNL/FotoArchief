<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class CatalogueModel extends Model
{
    use HasUlids;

    /**
     * ULIDs are stable public catalogue identifiers; database rows never expose
     * storage-provider keys as their identifiers.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
