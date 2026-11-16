<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Ingest\Exceptions\UnreadableRasterImage;
use App\Modules\Ingest\Support\BoundedProcess;
use RuntimeException;

/**
 * Converts TIFF and HEIC originals, which PHP's GD cannot decode, into one JPEG so the
 * normal derivative pipeline can build previews. The original is kept unchanged.
 *
 * The files are untrusted input: libvips runs without a shell, with a wall-clock limit,
 * with its untrusted loaders (ImageMagick and friends) blocked, with fail-on-error, and
 * with damage warnings treated as failure, because a HEIC whose decoder is missing or a
 * TIFF with a damaged tile can otherwise exit 0 with a grey or half-empty picture. Only the first page of a multi-page TIFF
 * is used. The pixel limit is checked on the original's header before decoding.
 *
 * A missing binary is an installation problem (RuntimeException, retryable once the
 * image is fixed); a bad file is permanent (UnreadableRasterImage).
 */
class RasterImageConverter
{
    public const TIFF = 'image/tiff';

    public const HEIC = 'image/heic';

    private const RENDER_LONG_SIDE = 2000;

    /** Warnings that mean pixels are missing, as opposed to harmless metadata remarks. */
    private const DAMAGE = '/error in tile|read error|corrupt|truncat|premature end|unsupported codec/i';

    /** HEIF brands that carry HEVC pictures; AVIF uses the same container and stays unsupported. */
    private const HEIC_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx'];

    public function __construct(
        private readonly ?string $vips = null,
        private readonly ?string $vipsheader = null,
        private readonly ?int $timeoutSeconds = null,
    ) {}

    /** Detects the format from the file's first bytes, never from its name. */
    public function detect(string $path): ?string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $head = (string) fread($handle, 64);
        } finally {
            fclose($handle);
        }
        // Classic TIFF ("II*\0" / "MM\0*") and BigTIFF ("II+\0" / "MM\0+").
        if (in_array(substr($head, 0, 4), ["II*\0", "MM\0*", "II+\0", "MM\0+"], true)) {
            return self::TIFF;
        }
        if (strlen($head) >= 16 && substr($head, 4, 4) === 'ftyp') {
            $declared = unpack('N', substr($head, 0, 4));
            $boxSize = min(strlen($head), max(16, is_array($declared) ? (int) $declared[1] : 0));
            // Major brand at 8, then (after the minor version) the compatible brands.
            $brands = [substr($head, 8, 4), ...str_split(substr($head, 16, $boxSize - 16), 4)];
            if (in_array('avif', $brands, true) || in_array('avis', $brands, true)) {
                return null;
            }
            if (array_intersect($brands, self::HEIC_BRANDS) !== []) {
                return self::HEIC;
            }
        }

        return null;
    }

    /** @return array{width: int, height: int} of the original (first page) */
    public function dimensions(string $path): array
    {
        $size = [];
        foreach (['width', 'height'] as $field) {
            $result = $this->run([$this->vipsheaderBinary(), '-f', $field, $path]);
            $value = trim($result['stdout']);
            if ($result['exit'] !== 0 || preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                throw new UnreadableRasterImage(__('uploads.raster.unreadable'));
            }
            $size[$field] = (int) $value;
        }

        return ['width' => $size['width'], 'height' => $size['height']];
    }

    /** @return string path to a temporary JPEG the caller must delete */
    public function toJpeg(string $path): string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'fotoarchief-raster-');
        if ($prefix === false) {
            throw new RuntimeException(__('uploads.storage_error'));
        }
        $output = $prefix.'.jpg';
        try {
            $result = $this->run([
                $this->vipsBinary(), 'thumbnail', $path.'[fail=true]', $output.'[Q=90]',
                (string) self::RENDER_LONG_SIDE, '--height', (string) self::RENDER_LONG_SIDE, '--size', 'down',
            ]);
            // Some decoders report damage only as a warning and still exit 0. Harmless
            // warnings (unknown private tags from scanner software) must not reject a scan.
            if ($result['exit'] !== 0 || preg_match(self::DAMAGE, $result['stderr']) === 1
                || ! is_file($output) || filesize($output) === 0) {
                throw new UnreadableRasterImage(__('uploads.raster.unreadable'));
            }
        } catch (\Throwable $exception) {
            if (is_file($output)) {
                unlink($output);
            }
            throw $exception;
        } finally {
            if (is_file($prefix)) {
                unlink($prefix);
            }
        }

        return $output;
    }

    private function vipsBinary(): string
    {
        return $this->vips ?? (string) config('ingest.raster.vips_binary', 'vips');
    }

    private function vipsheaderBinary(): string
    {
        return $this->vipsheader ?? (string) config('ingest.raster.vipsheader_binary', 'vipsheader');
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function run(array $command): array
    {
        $result = BoundedProcess::run(
            $command,
            $this->timeoutSeconds ?? (int) config('ingest.raster.timeout_seconds', 60),
            ['VIPS_BLOCK_UNTRUSTED' => '1'],
        );
        if ($result['missing']) {
            throw new RuntimeException(__('uploads.raster.unavailable'));
        }
        if ($result['timedOut'] || $result['exit'] === null) {
            throw new UnreadableRasterImage(__('uploads.raster.timeout'));
        }

        return ['exit' => $result['exit'], 'stdout' => $result['stdout'], 'stderr' => $result['stderr']];
    }
}
