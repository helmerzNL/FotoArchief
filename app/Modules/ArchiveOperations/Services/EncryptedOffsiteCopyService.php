<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class EncryptedOffsiteCopyService
{
    /**
     * @return array{disk: string, object_key: string, bytes: int, sha256: string}
     */
    public function copy(string $encryptedPath, string $objectKey): array
    {
        $source = realpath($encryptedPath);
        $manifestPath = $encryptedPath.'.manifest';
        if ($source === false || ! is_file($source) || is_link($encryptedPath)
            || ! is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Encrypted offsite copy requires a regular encrypted file and its manifest.');
        }
        if ($objectKey === '' || str_starts_with($objectKey, '/') || str_contains($objectKey, '..') || str_contains($objectKey, '\\')) {
            throw new RuntimeException('Encrypted offsite object key is unsafe.');
        }
        $expected = $this->manifestValue($manifestPath, 'encrypted_sha256');
        $actual = hash_file('sha256', $source);
        if ($expected === '' || $actual === false || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Encrypted offsite copy checksum does not match its manifest.');
        }
        $diskName = config('recovery.offsite_disk');
        if (! is_string($diskName) || $diskName === '' || $diskName === 'public') {
            throw new RuntimeException('BACKUP_OFFSITE_DISK must name a configured private filesystem disk.');
        }
        $disk = Storage::disk($diskName);
        $this->putStream($disk, $objectKey, $source);
        $this->putStream($disk, $objectKey.'.manifest', $manifestPath);
        if ($this->streamSha256($disk, $objectKey) !== $actual) {
            throw new RuntimeException('Encrypted offsite copy failed remote checksum verification.');
        }

        return [
            'disk' => $diskName,
            'object_key' => $objectKey,
            'bytes' => (int) filesize($source),
            'sha256' => $actual,
        ];
    }

    private function manifestValue(string $manifestPath, string $key): string
    {
        foreach (file($manifestPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            if ($name === $key) {
                return $value;
            }
        }

        return '';
    }

    private function putStream(Filesystem $disk, string $key, string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Encrypted offsite source could not be opened.');
        }
        try {
            if (! $disk->put($key, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Encrypted offsite destination rejected the copy.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function streamSha256(Filesystem $disk, string $key): string
    {
        $stream = $disk->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException('Encrypted offsite copy could not be read back.');
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
