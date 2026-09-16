<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Services;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetRight;
use App\Modules\DataExchange\Models\DataExport;
use App\Modules\DataExchange\Support\CsvWriter;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Turns authorised assets into a private export artifact with a manifest and
 * checksums. Storage keys and disks never leave this class: an export describes
 * files by name and checksum, never by their private storage location.
 */
class ExportArchiver
{
    public const COLUMNS = [
        'accession_number',
        'lock_version',
        'title',
        'description',
        'date_precision',
        'date_earliest',
        'date_latest',
        'date_display',
        'tags',
        'rights_holder',
        'rights_status',
        'rights_note',
    ];

    public function __construct(private readonly CsvWriter $csv) {}

    /**
     * @param  list<Asset>  $assets
     * @param  list<array{accession_number: string, reason: string}>  $skipped
     * @return array{storage_disk: string, storage_key: string, filename: string, byte_size: int, sha256: string, manifest: array<string, mixed>}
     */
    public function build(DataExport $export, array $assets, array $skipped): array
    {
        $diskName = (string) config('filesystems.default');
        $extension = match ($export->export_type) {
            'metadata_json' => 'json',
            'metadata_csv' => 'csv',
            default => 'zip',
        };
        $filename = 'fotoarchief-export-'.$export->id.'.'.$extension;
        $storageKey = 'exchange/exports/'.$export->id.'/'.str()->random(32).'.'.$extension;
        $maxBytes = (int) config('exchange.max_export_bytes');

        if ($export->export_type === 'package_zip') {
            return array_merge(
                $this->buildPackage($export, $assets, $skipped, $diskName, $storageKey, $maxBytes),
                ['filename' => $filename],
            );
        }

        $contents = $export->export_type === 'metadata_json'
            ? $this->metadataJson($export, $assets, $skipped)
            : $this->csv->toString(self::COLUMNS, array_map(fn (Asset $asset): array => $this->row($asset), $assets));
        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException('Export exceeds the configured maximum size.');
        }
        if (! Storage::disk($diskName)->put($storageKey, $contents, ['visibility' => 'private'])) {
            throw new RuntimeException('Export write failed.');
        }

        return [
            'storage_disk' => $diskName,
            'storage_key' => $storageKey,
            'filename' => $filename,
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'manifest' => [
                'export_id' => $export->id,
                'export_type' => $export->export_type,
                'asset_count' => count($assets),
                'files' => [['path' => $filename, 'kind' => 'metadata', 'byte_size' => strlen($contents), 'sha256' => hash('sha256', $contents)]],
                'skipped' => $skipped,
            ],
        ];
    }

    /**
     * @param  list<Asset>  $assets
     * @param  list<array{accession_number: string, reason: string}>  $skipped
     * @return array{storage_disk: string, storage_key: string, byte_size: int, sha256: string, manifest: array<string, mixed>}
     */
    private function buildPackage(DataExport $export, array $assets, array $skipped, string $diskName, string $storageKey, int $maxBytes): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required for package exports.');
        }
        $archivePath = tempnam(sys_get_temp_dir(), 'fa-export');
        if ($archivePath === false) {
            throw new RuntimeException('Export workspace unavailable.');
        }
        $temporaryFiles = [];
        $zip = new ZipArchive;
        $open = false;
        try {
            if ($zip->open($archivePath, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Export archive could not be created.');
            }
            $open = true;
            $entries = [];
            foreach ($assets as $asset) {
                foreach ($asset->files as $file) {
                    if ($file->storage_disk === null) {
                        $skipped[] = ['accession_number' => (string) $asset->accession_number, 'reason' => 'Bestand heeft geen opslagschijf (oude registratie).'];

                        continue;
                    }
                    $entries[] = $this->addFile($zip, $temporaryFiles, $file, $asset, $file->storage_key, $this->originalPath($asset, $file), 'original');
                    foreach ($this->derivatives($file) as $size => $key) {
                        $entries[] = $this->addFile($zip, $temporaryFiles, $file, $asset, $key, 'derivatives/'.$this->folder($asset).'/'.$size.'.jpg', 'derivative');
                    }
                }
            }
            $metadataJson = $this->metadataJson($export, $assets, $skipped);
            $metadataCsv = $this->csv->toString(self::COLUMNS, array_map(fn (Asset $asset): array => $this->row($asset), $assets));
            $entries[] = ['path' => 'metadata.json', 'kind' => 'metadata', 'byte_size' => strlen($metadataJson), 'sha256' => hash('sha256', $metadataJson)];
            $entries[] = ['path' => 'metadata.csv', 'kind' => 'metadata', 'byte_size' => strlen($metadataCsv), 'sha256' => hash('sha256', $metadataCsv)];
            $zip->addFromString('metadata.json', $metadataJson);
            $zip->addFromString('metadata.csv', $metadataCsv);
            $manifest = [
                'export_id' => $export->id,
                'export_type' => $export->export_type,
                'created_at' => now()->toIso8601String(),
                'asset_count' => count($assets),
                'files' => $entries,
                'skipped' => $skipped,
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $zip->addFromString('manifest.json', $manifestJson);
            $zip->addFromString('checksums.sha256', implode("\n", array_map(
                fn (array $entry): string => $entry['sha256'].'  '.$entry['path'],
                $entries,
            ))."\n");
            if (! $zip->close()) {
                throw new RuntimeException('Export archive could not be finished.');
            }
            $open = false;
            $size = filesize($archivePath);
            if ($size === false) {
                throw new RuntimeException('Export archive unreadable.');
            }
            if ($size > $maxBytes) {
                throw new RuntimeException('Export exceeds the configured maximum size.');
            }
            $stream = fopen($archivePath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Export archive unreadable.');
            }
            try {
                if (! Storage::disk($diskName)->writeStream($storageKey, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Export write failed.');
                }
            } finally {
                fclose($stream);
            }

            return [
                'storage_disk' => $diskName,
                'storage_key' => $storageKey,
                'byte_size' => $size,
                'sha256' => (string) hash_file('sha256', $archivePath),
                'manifest' => $manifest,
            ];
        } finally {
            if ($open) {
                $zip->close();
            }
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
            @unlink($archivePath);
        }
    }

    /**
     * @param  list<string>  $temporaryFiles
     * @return array{path: string, kind: string, byte_size: int, sha256: string}
     */
    private function addFile(ZipArchive $zip, array &$temporaryFiles, AssetFile $file, Asset $asset, string $key, string $path, string $kind): array
    {
        $disk = Storage::disk((string) $file->storage_disk);
        $source = $disk->readStream($key);
        if (! is_resource($source)) {
            throw new RuntimeException('Export source file unreadable.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'fa-item');
        if ($temporary === false) {
            fclose($source);
            throw new RuntimeException('Export workspace unavailable.');
        }
        $temporaryFiles[] = $temporary;
        $target = fopen($temporary, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Export workspace unavailable.');
        }
        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
        if (! $zip->addFile($temporary, $path)) {
            throw new RuntimeException('Export archive rejected a file.');
        }
        $size = filesize($temporary);

        return ['path' => $path, 'kind' => $kind, 'byte_size' => $size === false ? 0 : $size, 'sha256' => (string) hash_file('sha256', $temporary)];
    }

    /**
     * @return array<string, string>
     */
    private function derivatives(AssetFile $file): array
    {
        $derivatives = [];
        foreach ((array) $file->derivatives as $size => $key) {
            if (is_string($key) && is_string($size)) {
                $derivatives[$size] = $key;
            }
        }

        return $derivatives;
    }

    private function folder(Asset $asset): string
    {
        $folder = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $asset->accession_number);

        return $folder === null || $folder === '' ? $asset->id : $folder;
    }

    private function originalPath(Asset $asset, AssetFile $file): string
    {
        $name = basename((string) ($file->original_filename ?? $file->id));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($name === null || $name === '' || $name === '.' || $name === '..') {
            $name = $file->id;
        }

        return 'originals/'.$this->folder($asset).'/'.$name;
    }

    /**
     * @param  list<Asset>  $assets
     * @param  list<array{accession_number: string, reason: string}>  $skipped
     */
    private function metadataJson(DataExport $export, array $assets, array $skipped): string
    {
        return json_encode([
            'export_id' => $export->id,
            'export_type' => $export->export_type,
            'created_at' => now()->toIso8601String(),
            'asset_count' => count($assets),
            'assets' => array_map(fn (Asset $asset): array => $this->assetPayload($asset), $assets),
            'skipped' => $skipped,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(Asset $asset): array
    {
        $rights = $this->rights($asset);

        return [
            'id' => $asset->id,
            'accession_number' => $asset->accession_number,
            'lock_version' => $asset->lock_version,
            'title' => $asset->title,
            'description' => $asset->description,
            'catalogue_status' => $asset->catalogue_status,
            'date_precision' => $asset->date_precision,
            'date_earliest' => $this->date($asset, 'date_earliest'),
            'date_latest' => $this->date($asset, 'date_latest'),
            'date_display' => $asset->date_display,
            'tags' => $this->tags($asset),
            'rights' => [
                'rights_holder' => $rights['holder'],
                'verification_status' => $rights['status'],
                'note' => $rights['note'],
            ],
            'files' => $asset->files->map(fn (AssetFile $file): array => [
                'original_filename' => $file->original_filename,
                'media_type' => $file->media_type,
                'byte_size' => $file->byte_size,
                'sha256' => $file->sha256,
                'pixel_width' => $file->pixel_width,
                'pixel_height' => $file->pixel_height,
                'ingest_status' => $file->ingest_status,
                'scanner_status' => $file->scanner_status,
                'derivatives' => array_keys($this->derivatives($file)),
            ])->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function row(Asset $asset): array
    {
        $rights = $this->rights($asset);

        return [
            (string) $asset->accession_number,
            (string) $asset->lock_version,
            (string) $asset->title,
            (string) $asset->description,
            (string) $asset->date_precision,
            (string) $this->date($asset, 'date_earliest'),
            (string) $this->date($asset, 'date_latest'),
            (string) $asset->date_display,
            implode(', ', $this->tags($asset)),
            $rights['holder'],
            $rights['status'],
            $rights['note'],
        ];
    }

    /**
     * The newest rights record of an asset, flattened to plain strings. An
     * asset without rights exports as unverified rather than as empty.
     *
     * @return array{holder: string, status: string, note: string}
     */
    private function rights(Asset $asset): array
    {
        $latest = $asset->rights->sortBy('id')->last();
        if (! $latest instanceof AssetRight) {
            return ['holder' => '', 'status' => 'unverified', 'note' => ''];
        }
        $status = (string) $latest->verification_status;

        return [
            'holder' => (string) $latest->rights_holder,
            'status' => $status === '' ? 'unverified' : $status,
            'note' => (string) $latest->note,
        ];
    }

    /**
     * @return list<string>
     */
    private function tags(Asset $asset): array
    {
        $tags = [];
        foreach ($asset->tags as $tag) {
            $tags[] = (string) $tag->name;
        }
        sort($tags);

        return $tags;
    }

    private function date(Asset $asset, string $field): ?string
    {
        $value = $asset->getAttribute($field);
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
