<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use RuntimeException;

/**
 * A backup file or step that is refused for a reason the administrator can act on. It carries a
 * message key and parameters, worded when read in the reader's language.
 */
final class ArchiveBackupRefused extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
