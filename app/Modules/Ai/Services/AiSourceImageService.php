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
            throw new RuntimeException(__('ai.errors.source_missing', ['asset' => $asset->id]));
        }

        $disk = Storage::disk((string) ($file->storage_disk ?: config('filesystems.default')));
        $bytes = $disk->get($file->storage_key);
        if (! is_string($bytes) || ! hash_equals((string) $file->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException(__('ai.errors.source_checksum', ['asset' => $asset->id]));
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
            throw new RuntimeException(__('ai.errors.ai_derivative_missing'));
        }

        $bytes = $disk->get($key);
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException(__('ai.errors.ai_derivative_unreadable'));
        }

        set_error_handler(static fn (): bool => true);
        try {
            $source = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($source === false) {
            throw new RuntimeException(__('ai.errors.ai_derivative_decode'));
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

            throw new RuntimeException(__('ai.errors.ai_derivative_memory'));
        }

        imagefill($target, 0, 0, 16777215);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        try {
            if (! imagejpeg($target, null, 85)) {
                throw new RuntimeException(__('ai.errors.ai_derivative_encode'));
            }
            $encoded = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($target);
        }
        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException(__('ai.errors.ai_derivative_encoded_missing'));
        }

        return $encoded;
    }

    private function scan(AssetFile $file, string $bytes): void
    {
        if (config('ingest.scanner') === 'none') {
            throw new RuntimeException(__('ai.errors.unscanned_source'));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-ai-scan-');
        if ($temporary === false) {
            throw new RuntimeException(__('ai.errors.scan_temp_create'));
        }

        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException(__('ai.errors.scan_temp_write'));
            }
            if ($this->scanner->scan($temporary) !== 'clean') {
                throw new RuntimeException(__('ai.errors.scan_not_clean'));
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
