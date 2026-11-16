<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Exceptions;

use App\Modules\ArchiveOperations\Exceptions\PermanentOperationFailure;

/** Microsoft no longer accepts the stored refresh token (expired, revoked or app changed). */
final class OneDriveReconnectRequired extends PermanentOperationFailure {}
