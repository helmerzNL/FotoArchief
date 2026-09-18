<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\BackupRecord;
use RuntimeException;

final class BackupRegisterService
{
    /** @return array{location: string, version: string, manifest_sha256: string, byte_size: int} */
    public function inspect(string $directory): array
    {
        $location = realpath($directory);
        if ($location === false || ! is_dir($location) || is_link($directory)) {
            throw new RuntimeException(__('recovery.errors.backup_directory'));
        }
        $manifest = '';
        $bytes = 0;
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT', 'SHA256SUMS'] as $name) {
            $path = $location.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path) || is_link($path)) {
                throw new RuntimeException(__('recovery.errors.backup_file', ['file' => $name]));
            }
            $size = filesize($path);
            $hash = hash_file('sha256', $path);
            if ($size === false || $hash === false) {
                throw new RuntimeException(__('recovery.errors.backup_verify', ['file' => $name]));
            }
            $bytes += $size;
            if ($name !== 'SHA256SUMS') {
                $manifest .= $hash.'  '.$name."\n";
            }
        }
        if (file_get_contents($location.'/FORMAT') !== "fotoarchief-local-backup-v1\n"
            || file_get_contents($location.'/SHA256SUMS') !== $manifest) {
            throw new RuntimeException(__('recovery.errors.backup_manifest'));
        }
        $version = trim((string) file_get_contents($location.'/VERSION'));
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new RuntimeException(__('recovery.errors.backup_version'));
        }

        return ['location' => $location, 'version' => $version, 'manifest_sha256' => hash('sha256', $manifest), 'byte_size' => $bytes];
    }

    public function register(string $directory): BackupRecord
    {
        $data = $this->inspect($directory);

        return BackupRecord::query()->updateOrCreate(['manifest_sha256' => $data['manifest_sha256']], [
            ...$data, 'checksum_verified_at' => now(),
        ]);
    }
}
