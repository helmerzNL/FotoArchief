<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $item_id
 * @property string|null $ctag
 * @property string|null $quarantine_upload_id
 * @property string $outcome
 * @property string $original_path
 */
class OneDriveImportedItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'onedrive_imported_items';

    protected $guarded = [];
}
