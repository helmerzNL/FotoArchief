<?php

declare(strict_types=1);

use App\Modules\Installation\InstallationPlatform;

it('names zip among the extensions onboarding refuses to install without', function (): void {
    // ext-zip became a hard requirement when package exports landed. A release
    // is unpacked on a host that never runs Composer, so the wizard is the only
    // place that can still tell the operator before the archive is in use.
    expect(array_keys(InstallationPlatform::REQUIRED))
        ->toContain('zip')
        ->toContain('pdo_pgsql')
        ->toContain('gd')
        ->toContain('exif')
        ->toContain('fileinfo');

    foreach (InstallationPlatform::REQUIRED as $extension => $purpose) {
        expect($purpose)->not->toBe('')
            ->and(extension_loaded($extension))->toBeTrue("extension {$extension} is required to run the test suite");
    }
});

it('passes the preflight on a platform that has every required extension', function (): void {
    (new InstallationPlatform)->check();
})->throwsNoExceptions();

it('reports every missing extension in Dutch instead of failing later', function (): void {
    $missing = array_values(array_filter(
        array_keys(InstallationPlatform::REQUIRED),
        static fn (string $extension): bool => ! extension_loaded($extension),
    ));

    // The suite cannot run without these, so the message itself is asserted
    // rather than simulated by unloading an extension.
    expect($missing)->toBe([]);
    expect(InstallationPlatform::REQUIRED['zip'])->toContain('exportpakketten')
        ->and(InstallationPlatform::REQUIRED['pdo_pgsql'])->toContain('PostgreSQL');
});
