<?php

declare(strict_types=1);

namespace App\Modules\Installation;

/**
 * Checks the PHP extensions the application cannot run without.
 *
 * Composer declares them in composer.json, but a release is unpacked on a host
 * that never runs Composer, so nothing verifies them there. Without this check
 * a missing extension only surfaces much later, as a photo that will not
 * process or an export that will not build, with no hint of the real cause.
 */
class InstallationPlatform
{
    /**
     * @var array<string, string> extension name => what breaks without it, in Dutch
     */
    public const REQUIRED = [
        'pdo' => 'databaseverbindingen',
        'pdo_pgsql' => 'PostgreSQL als bron van waarheid',
        'gd' => 'verkleinde weergaven van foto\'s',
        'exif' => 'opnamedatum en camera-informatie uit foto\'s',
        'fileinfo' => 'controle van het bestandstype bij uploads',
        'intl' => 'Nederlandse datum- en tekstopmaak',
        'mbstring' => 'veilige UTF-8 tekstverwerking',
        'openssl' => 'versleuteling, HTTPS-integraties en sleutels',
        'zip' => 'exportpakketten met originelen en afgeleiden',
    ];

    public function check(): void
    {
        $missing = [];
        foreach (self::REQUIRED as $extension => $purpose) {
            if (! extension_loaded($extension)) {
                $missing[] = $extension.' (nodig voor '.$purpose.')';
            }
        }
        if ($missing !== []) {
            throw new InstallationFailure('De PHP-installatie mist verplichte extensies: '.implode(', ', $missing).'. Zet ze aan in php.ini en start PHP opnieuw.');
        }
    }
}
