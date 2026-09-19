<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\BackupRecord;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class BackupRegisterService
{
    /**
     * @return array{
     *     location: string,
     *     version: string,
     *     manifest_sha256: string,
     *     byte_size: int,
     *     manifest_schema_version: int,
     *     backup_format: string,
     *     manifest: array<string, mixed>|null
     * }
     */
    public function inspect(string $directory): array
    {
        $location = realpath($directory);
        if ($location === false || ! is_dir($location) || is_link($directory)) {
            throw new RuntimeException(__('recovery.errors.backup_directory'));
        }
        $formatPath = $location.'/FORMAT';
        if (! is_file($formatPath) || is_link($formatPath)) {
            throw new RuntimeException(__('recovery.errors.backup_file', ['file' => 'FORMAT']));
        }
        $format = trim((string) file_get_contents($formatPath));
        $schemaVersion = match ($format) {
            'fotoarchief-local-backup-v1' => 1,
            'fotoarchief-local-backup-v2' => 2,
            default => throw new RuntimeException(__('recovery.errors.backup_manifest')),
        };
        $componentNames = ['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT'];
        if ($schemaVersion === 2) {
            $componentNames[] = 'BACKUP-MANIFEST.json';
        }
        $manifest = '';
        $bytes = 0;
        foreach ([...$componentNames, 'SHA256SUMS'] as $name) {
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
        if (file_get_contents($location.'/SHA256SUMS') !== $manifest) {
            throw new RuntimeException(__('recovery.errors.backup_manifest'));
        }
        $version = trim((string) file_get_contents($location.'/VERSION'));
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new RuntimeException(__('recovery.errors.backup_version'));
        }
        $structuredManifest = $schemaVersion === 2
            ? $this->inspectStructuredManifest($location, $format, $version)
            : null;

        return [
            'location' => $location,
            'version' => $version,
            'manifest_sha256' => hash('sha256', $manifest),
            'byte_size' => $bytes,
            'manifest_schema_version' => $schemaVersion,
            'backup_format' => $format,
            'manifest' => $structuredManifest,
        ];
    }

    public function register(string $directory): BackupRecord
    {
        $data = $this->inspect($directory);

        return BackupRecord::query()->updateOrCreate(['manifest_sha256' => $data['manifest_sha256']], [
            ...$data, 'checksum_verified_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function inspectStructuredManifest(string $location, string $format, string $version): array
    {
        try {
            $manifest = json_decode((string) file_get_contents($location.'/BACKUP-MANIFEST.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(__('recovery.errors.backup_manifest'));
        }
        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 2
            || ($manifest['format'] ?? null) !== $format
            || ($manifest['app_version'] ?? null) !== $version
            || ! is_string($manifest['created_at'] ?? null)
            || DateTimeImmutable::createFromFormat(DATE_ATOM, $manifest['created_at']) === false
            || ! is_array($manifest['components'] ?? null)) {
            throw new RuntimeException(__('recovery.errors.backup_manifest'));
        }
        foreach (['database.dump', 'storage-app.tar'] as $name) {
            $component = $manifest['components'][$name] ?? null;
            $path = $location.'/'.$name;
            if (! is_array($component)
                || ($component['sha256'] ?? null) !== hash_file('sha256', $path)
                || ($component['bytes'] ?? null) !== filesize($path)) {
                throw new RuntimeException(__('recovery.errors.backup_manifest'));
            }
        }

        return $manifest;
    }
}
