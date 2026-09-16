<?php

declare(strict_types=1);

it('keeps package and release metadata bilingual with Dutch first', function (): void {
    $composer = json_decode(
        (string) file_get_contents(base_path('composer.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $releaseWorkflow = (string) file_get_contents(base_path('.github/workflows/release.yml'));

    expect($composer['description'])
        ->toContain('Historische beeldbank')
        ->toContain('Historical image archive')
        ->and($dockerfile)
        ->toContain('org.opencontainers.image.description=')
        ->toContain('Historische beeldbank')
        ->toContain('Historical image archive')
        ->and($releaseWorkflow)
        ->toContain('## Nederlands')
        ->toContain('## English')
        ->toContain('Testrelease / Test release')
        ->and(strpos($releaseWorkflow, '## Nederlands'))
        ->toBeLessThan(strpos($releaseWorkflow, '## English'));
});
