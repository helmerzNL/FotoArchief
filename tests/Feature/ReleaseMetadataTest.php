<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

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
        ->toContain('Testrelease / Test release')
        ->toContain('run: sh scripts/check-release-notes.sh "$GITHUB_REF_NAME"')
        ->toContain('--notes-file "docs/releases/$GITHUB_REF_NAME.md" dist/*');
});

it('keeps the version-specific release documents bilingual with Dutch first', function (): void {
    $files = File::files(base_path('docs/releases'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $notes = $file->getContents();

        expect($notes)
            ->toContain('## Nederlands')
            ->toContain('## English')
            ->and(strpos($notes, '## Nederlands'))
            ->toBeLessThan(strpos($notes, '## English'));
    }
});
