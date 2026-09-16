<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;

final class UserVisibleTextScanner
{
    /**
     * @var list<string>
     */
    public const EXTRACTED_FILES = [
        'app/Http/Controllers/InstallationController.php',
        'app/Http/Controllers/SessionController.php',
        'app/Http/Middleware/EnsureActiveUserSession.php',
        'app/Modules/Installation/InstallationBootstrap.php',
        'app/Modules/Installation/InstallationDatabase.php',
        'app/Modules/Installation/InstallationFailure.php',
        'app/Modules/Installation/InstallationGate.php',
        'app/Modules/Installation/InstallationPlatform.php',
        'app/Modules/Installation/InstallationRunner.php',
        'app/Modules/Installation/InstallationSettings.php',
        'app/Modules/Installation/InstallationState.php',
        'app/Modules/Installation/InstallationStorage.php',
        'app/Modules/Installation/InstallationStore.php',
        'app/Modules/Installation/InstallationText.php',
        'resources/views/admin/dashboard.blade.php',
        'resources/views/auth/login.blade.php',
        'resources/views/identity/invitations/accept.blade.php',
        'resources/views/identity/invitations/create.blade.php',
        'resources/views/identity/security/show.blade.php',
        'resources/views/identity/users/index.blade.php',
        'resources/views/installation/setup.blade.php',
        'resources/views/layouts/app.blade.php',
    ];

    /**
     * @return list<array{file: string, line: int, kind: string, text: string, batch: string}>
     */
    public function remainingInventory(): array
    {
        $files = [];
        foreach (['app', 'resources/views'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $relative = $directory.'/'.$relative;
                if (! in_array($relative, self::EXTRACTED_FILES, true) && ($file->getExtension() === 'php' || str_ends_with($relative, '.blade.php'))) {
                    $files[] = $relative;
                }
            }
        }
        sort($files);

        $entries = [];
        foreach ($files as $file) {
            $entries = array_merge($entries, str_ends_with($file, '.blade.php') ? $this->scanBlade($file) : $this->scanPhp($file));
        }

        return $this->unique($entries);
    }

    /**
     * @param  list<string>  $files
     * @return list<array{file: string, line: int, kind: string, text: string, batch: string}>
     */
    public function scanExtractedBladeFiles(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            $entries = array_merge($entries, $this->scanBlade($file));
        }

        return $this->unique($entries);
    }

    /**
     * @return list<array{file: string, line: int, kind: string, text: string, batch: string}>
     */
    private function scanBlade(string $relative): array
    {
        $entries = [];
        $contents = File::get(base_path($relative));
        $contents = preg_replace_callback(
            '/<(script|style)\b[^>]*>.*?<\/\1>/is',
            static fn (array $match): string => (string) preg_replace('/[^\r\n]/', ' ', $match[0]),
            $contents,
        ) ?? $contents;

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            $lineNumber = $index + 1;
            foreach ($this->attributeTexts($line) as $text) {
                $entries[] = $this->entry($relative, $lineNumber, 'blade_attribute', $text);
            }

            $withoutBlade = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|@[A-Za-z_][A-Za-z0-9_]*(?:\([^)]*\))?/', ' ', $line) ?? $line;
            $parts = preg_split('/<[^>]+>/', $withoutBlade) ?: [];
            foreach ($parts as $part) {
                $text = $this->normalize($part);
                if ($this->isCandidate($text)) {
                    $entries[] = $this->entry($relative, $lineNumber, 'blade_text', $text);
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<array{file: string, line: int, kind: string, text: string, batch: string}>
     */
    private function scanPhp(string $relative): array
    {
        $entries = [];
        foreach (token_get_all(File::get(base_path($relative))) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $text = $this->normalize($this->decodePhpString($token[1]));
            if ($this->isCandidate($text)) {
                $entries[] = $this->entry($relative, $token[2], 'php_string', $text);
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function attributeTexts(string $line): array
    {
        preg_match_all('/\b(?:aria-label|placeholder|title|alt)="([^"{]+)"/u', $line, $matches);

        return array_values(array_filter(array_map($this->normalize(...), $matches[1] ?? []), $this->isCandidate(...)));
    }

    private function decodePhpString(string $value): string
    {
        $quote = $value[0];
        $inner = substr($value, 1, -1);

        return $quote === '"' ? stripcslashes($inner) : str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }

    private function normalize(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }

    private function isCandidate(string $text): bool
    {
        if ($text === '' || strlen($text) < 2 || str_contains($text, '{{') || str_contains($text, '$')) {
            return false;
        }
        if (preg_match('/^[a-z0-9_.:\/\\\\-]+$/i', $text) === 1 || str_contains($text, '::')) {
            return false;
        }

        return preg_match('/\s|[À-ÿ’]|^[A-ZÀ-Þ][\p{L}0-9\'’() -]+$/u', $text) === 1;
    }

    /**
     * @return array{file: string, line: int, kind: string, text: string, batch: string}
     */
    private function entry(string $file, int $line, string $kind, string $text): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'kind' => $kind,
            'text' => $text,
            'batch' => $this->batch($file),
        ];
    }

    private function batch(string $file): string
    {
        return match (true) {
            str_contains($file, '/Modules/Ai/'), str_contains($file, 'resources/views/ai/') => 'ai',
            str_contains($file, 'resources/views/catalogue/') => 'catalogue',
            str_contains($file, 'resources/views/exchange/') => 'exchange',
            str_contains($file, 'resources/views/identity/') => 'identity',
            str_contains($file, 'resources/views/operations/') => 'operations',
            str_contains($file, 'resources/views/public/') || str_contains($file, '/Publication/') => 'public_portal',
            str_contains($file, 'resources/views/admin/assets/') || str_contains($file, 'AdminAssetController.php') => 'asset_admin',
            str_contains($file, 'resources/views/admin/publications/') || str_contains($file, 'resources/views/admin/suggestions/') => 'publication_admin',
            default => 'shared_remaining',
        };
    }

    /**
     * @param  list<array{file: string, line: int, kind: string, text: string, batch: string}>  $entries
     * @return list<array{file: string, line: int, kind: string, text: string, batch: string}>
     */
    private function unique(array $entries): array
    {
        $seen = [];

        return array_values(array_filter($entries, static function (array $entry) use (&$seen): bool {
            $key = implode("\0", [$entry['file'], (string) $entry['line'], $entry['kind'], $entry['text']]);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }
}
