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
     * @var array<string, string> extension name => translation key for what breaks without it
     */
    public const REQUIRED = [
        'pdo' => 'onboarding.setup.platform.purposes.pdo',
        'pdo_pgsql' => 'onboarding.setup.platform.purposes.pdo_pgsql',
        'gd' => 'onboarding.setup.platform.purposes.gd',
        'exif' => 'onboarding.setup.platform.purposes.exif',
        'fileinfo' => 'onboarding.setup.platform.purposes.fileinfo',
        'intl' => 'onboarding.setup.platform.purposes.intl',
        'mbstring' => 'onboarding.setup.platform.purposes.mbstring',
        'openssl' => 'onboarding.setup.platform.purposes.openssl',
        'zip' => 'onboarding.setup.platform.purposes.zip',
    ];

    public function check(): void
    {
        $missing = [];
        foreach (self::REQUIRED as $extension => $purpose) {
            if (! extension_loaded($extension)) {
                $missing[] = InstallationText::get('onboarding.setup.platform.purpose_format', ['extension' => $extension, 'purpose' => InstallationText::get($purpose)]);
            }
        }
        if ($missing !== []) {
            throw new InstallationFailure(InstallationText::get('onboarding.setup.errors.missing_extensions', ['extensions' => implode(', ', $missing)]));
        }
    }

    /**
     * @return array<string, string>
     */
    public static function requiredPurposes(): array
    {
        return array_map(static fn (string $key): string => InstallationText::get($key), self::REQUIRED);
    }
}
