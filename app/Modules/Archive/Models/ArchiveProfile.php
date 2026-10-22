<?php

declare(strict_types=1);

namespace App\Modules\Archive\Models;

use App\Modules\Installation\InstallationStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * The historical society that owns this installation (one installation per
 * society). Recorded during first-start onboarding.
 */
class ArchiveProfile extends Model
{
    public const ID = 1;

    protected $table = 'archive_profile';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'name'];

    /**
     * The profile for display, or null before installation has completed or
     * when none was recorded. Never touches the database while the wizard is
     * still running, because the connection is not configured yet.
     */
    public static function current(): ?self
    {
        if (config('installation.enabled') && ! app(InstallationStore::class)->completed()) {
            return null;
        }

        try {
            return static::query()->find(self::ID);
        } catch (QueryException) {
            // The table does not exist yet in the window before deployment migrations
            // finish; omitting the society's name is better than failing every page.
            return null;
        }
    }
}
