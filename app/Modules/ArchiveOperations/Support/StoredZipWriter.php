<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Support;

use RuntimeException;
use Throwable;

/**
 * Appends entries to a ZIP file on disk across several processes, then writes the central
 * directory. It exists because an archive backup is built by a queue job in bounded steps:
 * libzip rewrites the whole archive whenever an opened archive changes, which would copy every
 * original again on each step.
 *
 * Originals are stored (method 0, not deflated: photographs do not compress); backup.json may be
 * deflated. The local header is written first and patched with the CRC-32 and the sizes once the
 * data is in, so no data descriptor is needed. ZIP64 records are written where a size, an offset
 * or the number of entries needs them. The entries written so far are kept in a side file
 * ("<zip>.entries", one JSON object per line) and a step that died halfway is cut off again on
 * the next open, so a step can always be repeated.
 */
final class StoredZipWriter
{
    /** The value that marks a field as "see the ZIP64 record". */
    private const int MAX32 = 0xFFFFFFFF;

    /** From this size or offset on, a ZIP64 record is needed (equal to MAX32; a constant of its own so a test can lower it). */
    private const int LIMIT = 0xFFFFFFFF;

    /** @var resource */
    private $handle;

    /** @var list<array{name: string, method: int, crc: int, compressed: int, size: int, offset: int, end: int, time: int, date: int}> */
    private array $entries = [];

    private function __construct(private readonly string $path)
    {
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the backup archive for writing.');
        }
        $this->handle = $handle;
        $end = 0;
        if (is_file($this->entriesPath())) {
            foreach (file($this->entriesPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $end = max(0, (int) ($entry['end'] ?? 0));
                    $this->entries[] = [
                        'name' => (string) ($entry['name'] ?? ''),
                        'method' => (int) ($entry['method'] ?? 0),
                        'crc' => (int) ($entry['crc'] ?? 0),
                        'compressed' => (int) ($entry['compressed'] ?? 0),
                        'size' => (int) ($entry['size'] ?? 0),
                        'offset' => (int) ($entry['offset'] ?? 0),
                        'end' => $end,
                        'time' => (int) ($entry['time'] ?? 0),
                        'date' => (int) ($entry['date'] ?? 0),
                    ];
                }
            }
        }
        // Whatever a step wrote after its last complete entry is cut off.
        ftruncate($this->handle, $end);
        fseek($this->handle, $end);
    }

    public static function open(string $path): self
    {
        return new self($path);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Copies a stream into a new entry and returns what was written.
     *
     * @param  resource  $input
     * @return array{crc: int, size: int, compressed: int, sha256: string}
     */
    public function addStream(string $name, $input, ?int $expectedSize = null, bool $deflate = false, ?int $maxBytes = null): array
    {
        $offset = max(0, (int) ftell($this->handle));
        $zip64 = ! $deflate && $expectedSize !== null && $expectedSize >= self::LIMIT;
        [$time, $date] = $this->dosTime(time());
        $method = $deflate ? 8 : 0;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';

        try {
            $this->write(pack('VvvvvvVVVvv', 0x04034B50, $zip64 ? 45 : 20, 0x0800, $method, $time, $date, 0, $zip64 ? self::MAX32 : 0, $zip64 ? self::MAX32 : 0, strlen($name), strlen($extra)).$name.$extra);

            $crc = hash_init('crc32b');
            $sha = hash_init('sha256');
            $size = 0;
            $compressed = 0;
            $deflater = null;
            if ($deflate) {
                $deflater = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
                if ($deflater === false) {
                    throw new RuntimeException('Cannot start compressing backup.json.');
                }
            }
            while (! feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Reading a file for the backup failed.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($maxBytes !== null && $size > $maxBytes) {
                    throw new RuntimeException('A file for the backup is larger than allowed.');
                }
                hash_update($crc, $chunk);
                hash_update($sha, $chunk);
                $out = $deflater !== null ? deflate_add($deflater, $chunk, ZLIB_NO_FLUSH) : $chunk;
                if ($out === false) {
                    throw new RuntimeException('Compressing backup.json failed.');
                }
                $compressed += strlen($out);
                $this->write($out);
            }
            if ($deflater !== null) {
                $out = deflate_add($deflater, '', ZLIB_FINISH);
                if ($out === false) {
                    throw new RuntimeException('Compressing backup.json failed.');
                }
                $compressed += strlen($out);
                $this->write($out);
            }
            if ($expectedSize !== null && $size !== $expectedSize) {
                throw new RuntimeException('A file for the backup does not have the size the catalogue records.');
            }
            if (! $zip64 && ($size >= self::LIMIT || $compressed >= self::LIMIT)) {
                throw new RuntimeException('An entry without a known size of 4 GiB or more cannot be written.');
            }
            $crcValue = (int) hexdec(hash_final($crc));
            $end = (int) ftell($this->handle);

            fseek($this->handle, $offset + 14);
            $this->write($zip64 ? pack('V', $crcValue) : pack('VVV', $crcValue, $compressed, $size));
            if ($zip64) {
                fseek($this->handle, $offset + 30 + strlen($name) + 4);
                $this->write(pack('PP', $size, $compressed));
            }
            fseek($this->handle, $end);
            fflush($this->handle);
        } catch (Throwable $exception) {
            ftruncate($this->handle, $offset);
            fseek($this->handle, $offset);
            throw $exception;
        }

        $entry = ['name' => $name, 'method' => $method, 'crc' => $crcValue, 'compressed' => $compressed, 'size' => $size, 'offset' => $offset, 'end' => $end, 'time' => $time, 'date' => $date];
        $this->entries[] = $entry;
        file_put_contents($this->entriesPath(), json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

        return ['crc' => $crcValue, 'size' => $size, 'compressed' => $compressed, 'sha256' => hash_final($sha)];
    }

    /** Writes the central directory and closes the archive. */
    public function finish(): void
    {
        $start = (int) ftell($this->handle);
        foreach ($this->entries as $entry) {
            $fields = '';
            $size = $entry['size'];
            $compressed = $entry['compressed'];
            $offset = $entry['offset'];
            if ($size >= self::LIMIT) {
                $fields .= pack('P', $size);
                $size = self::MAX32;
            }
            if ($compressed >= self::LIMIT) {
                $fields .= pack('P', $compressed);
                $compressed = self::MAX32;
            }
            if ($offset >= self::LIMIT) {
                $fields .= pack('P', $offset);
                $offset = self::MAX32;
            }
            $extra = $fields === '' ? '' : pack('vv', 0x0001, strlen($fields)).$fields;
            $version = $extra === '' ? 20 : 45;
            $this->write(pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 0x0300 | 45, $version, 0x0800, $entry['method'], $entry['time'], $entry['date'], $entry['crc'], $compressed, $size, strlen($entry['name']), strlen($extra), 0, 0, 0, 0100644 << 16, $offset).$entry['name'].$extra);
        }
        $end = (int) ftell($this->handle);
        $directorySize = $end - $start;
        $count = count($this->entries);

        if ($count >= 0xFFFF || $start >= self::LIMIT || $directorySize >= self::LIMIT) {
            $this->write(pack('VPvvVVPPPP', 0x06064B50, 44, 0x0300 | 45, 45, 0, 0, $count, $count, $directorySize, $start));
            $this->write(pack('VVPV', 0x07064B50, 0, $end, 1));
        }
        $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, min($count, 0xFFFF), min($count, 0xFFFF), $directorySize >= self::LIMIT ? self::MAX32 : $directorySize, $start >= self::LIMIT ? self::MAX32 : $start, 0));
        fflush($this->handle);
        fclose($this->handle);
        @unlink($this->entriesPath());
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    private function write(string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->handle, substr($bytes, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Writing the backup archive failed (disk full?).');
            }
            $written += $result;
        }
    }

    private function entriesPath(): string
    {
        return $this->path.'.entries';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dosTime(int $timestamp): array
    {
        $parts = getdate($timestamp);
        $year = max(1980, $parts['year']);

        return [
            ($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2),
            (($year - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'],
        ];
    }
}
