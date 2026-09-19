<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class S3ProtectionPolicy
{
    /** @return array{disk: string, bucket: string, versioning: 'Enabled'} */
    public function verifyVersioning(string $diskName): array
    {
        $config = config("filesystems.disks.{$diskName}");
        if (! is_array($config) || ($config['driver'] ?? null) !== 's3' || ! is_string($config['bucket'] ?? null) || $config['bucket'] === '') {
            throw new RuntimeException("Storage disk [{$diskName}] is not a configured S3 disk.");
        }
        $disk = Storage::disk($diskName);
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException("Storage disk [{$diskName}] does not expose an S3 client.");
        }
        try {
            $status = $disk->getClient()->getBucketVersioning(['Bucket' => $config['bucket']])->get('Status');
        } catch (\Throwable $exception) {
            throw new RuntimeException('S3 versioning could not be verified: '.$exception::class, previous: $exception);
        }
        if ($status !== 'Enabled') {
            throw new RuntimeException("S3 versioning must be enabled for bucket [{$config['bucket']}].");
        }

        return ['disk' => $diskName, 'bucket' => $config['bucket'], 'versioning' => 'Enabled'];
    }
}
