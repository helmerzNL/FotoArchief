<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Services\MalwareScanner;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AiSourceImageService
{
    public function __construct(
        private readonly MalwareScanner $scanner,
        private readonly AiConfigurationService $configuration,
    ) {}

    /**
     * @return array{file: AssetFile, bytes: string}
     */
    public function load(Asset $asset): array
    {
        $file = $asset->files
            ->where('is_primary', true)
            ->where('ingest_status', 'ready_private')
            ->first();

        if (! $file instanceof AssetFile) {
            throw new RuntimeException("Foto {$asset->id} heeft geen primair verwerkt bestand voor AI.");
        }

        $disk = Storage::disk((string) ($file->storage_disk ?: config('filesystems.default')));
        $bytes = $disk->get($file->storage_key);
        if (! is_string($bytes) || ! hash_equals((string) $file->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException("Het primaire bestand van foto {$asset->id} ontbreekt of wijkt af van de opgeslagen checksum.");
        }

        if ($file->scanner_status !== 'clean') {
            $this->scan($file, $bytes);
        }

        return ['file' => $file, 'bytes' => $this->derivative($file, $disk)];
    }

    private function derivative(AssetFile $file, FilesystemAdapter $disk): string
    {
        $key = $file->derivatives['preview1200'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('De metadata-arme AI-afgeleide ontbreekt.');
        }

        $bytes = $disk->get($key);
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('De metadata-arme AI-afgeleide kon niet worden gelezen.');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $source = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($source === false) {
            throw new RuntimeException('De metadata-arme AI-afgeleide kon niet veilig worden gedecodeerd.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $limit = (int) $this->configuration->effective()['derivative_max_pixels'];
        $ratio = min(1, $limit / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);

            throw new RuntimeException('Geheugen voor de AI-afgeleide kon niet worden gereserveerd.');
        }

        imagefill($target, 0, 0, 16777215);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        try {
            if (! imagejpeg($target, null, 85)) {
                throw new RuntimeException('De AI-afgeleide kon niet als JPEG worden gecodeerd.');
            }
            $encoded = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($target);
        }
        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('De gecodeerde AI-afgeleide is niet beschikbaar.');
        }

        return $encoded;
    }

    private function scan(AssetFile $file, string $bytes): void
    {
        if (config('ingest.scanner') === 'none') {
            throw new RuntimeException('Het primaire bestand is niet malwaregescand. Activeer eerst ClamAV; probeer de AI-taak daarna opnieuw om het bestand veilig te hercontroleren.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-ai-scan-');
        if ($temporary === false) {
            throw new RuntimeException('Tijdelijke opslag voor de malwarecontrole kon niet worden aangemaakt.');
        }

        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Het bestand kon niet voor de malwarecontrole worden klaargezet.');
            }
            if ($this->scanner->scan($temporary) !== 'clean') {
                throw new RuntimeException('De malwarecontrole heeft het bestand niet als schoon vrijgegeven.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        $file->forceFill([
            'scanner_status' => 'clean',
            'scanned_at' => now(),
        ])->save();
    }
}
