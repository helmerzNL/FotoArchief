<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Ingest\Exceptions\UnreadableImage;
use App\Modules\Ingest\Support\DecodedImage;
use GdImage;
use RuntimeException;

/**
 * Turns an original file into an upright GD image plus the sizes to record, for every
 * format the ingest pipeline accepts. Ingest and preview regeneration both use this, so
 * a format that can be imported can also have its previews rebuilt.
 *
 * GD reads JPEG, PNG, WebP and BMP directly. A PDF has its first page rendered by
 * poppler, TIFF and HEIC are converted by libvips; the temporary JPEG those produce is
 * always deleted before this returns or throws.
 *
 * Errors: bad image data throws UnreadableImage, UnreadablePdf or UnreadableRasterImage
 * (permanent; callers show them as a validation error). A missing poppler or libvips
 * binary is a plain RuntimeException (an installation problem that can be retried).
 */
class OriginalImageDecoder
{
    /** @var array<int, string> image types GD decodes, with their media type */
    public const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_BMP => 'image/bmp'];

    /** Long-side limits of the JPEG previews. */
    public const PREVIEW_LIMITS = [300, 1200, 2000];

    private readonly PdfPageRenderer $pdfs;

    private readonly RasterImageConverter $rasters;

    public function __construct(?PdfPageRenderer $pdfs = null, ?RasterImageConverter $rasters = null)
    {
        $this->pdfs = $pdfs ?? new PdfPageRenderer;
        $this->rasters = $rasters ?? new RasterImageConverter;
    }

    public function decode(string $path): DecodedImage
    {
        $rendered = null;
        $pdfPages = null;
        $sourceType = null;
        $originalSize = null;
        try {
            // A PDF is stored unchanged; its first page is rendered once and then runs through
            // the same decoder, size limits and derivative steps as any other image.
            if ($this->pdfs->isPdf($path)) {
                ['path' => $rendered, 'pages' => $pdfPages] = $this->pdfs->renderFirstPage($path);
                $sourceType = 'application/pdf';
            } elseif (($rasterType = $this->rasters->detect($path)) !== null) {
                // TIFF and HEIC are kept unchanged too; the pixel limit applies to the
                // original before it is decoded, then one JPEG feeds the same steps.
                $originalSize = $this->rasters->dimensions($path);
                if ((int) config('ingest.max_image_pixels') < $originalSize['width'] * $originalSize['height']) {
                    throw new UnreadableImage(__('shared.generated.t_41c61553c32261d4'));
                }
                $rendered = $this->rasters->toJpeg($path);
                $sourceType = $rasterType;
            }
            $imagePath = $rendered ?? $path;

            // Decoder warnings mean damaged data: a permanent failure, not a silent retry loop.
            set_error_handler(function (): never {
                throw new UnreadableImage(__('shared.generated.t_3c2421dae8c49b8b'));
            });
            try {
                $info = getimagesize($imagePath);
                if ($info === false || ! isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
                    throw new UnreadableImage(__('shared.generated.t_212ba5faa17b76ed'));
                }
                [$width, $height, $type] = $info;
                $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
                $budget = ($memoryLimit < 0 ? 536870912 : $memoryLimit) - memory_get_usage(true) - 67108864;
                if ($width * $height > (int) config('ingest.max_image_pixels') || $width * $height * 12 > $budget) {
                    throw new UnreadableImage(__('shared.generated.t_41c61553c32261d4'));
                }
                // Only a real JPEG original carries orientation that still applies; a rendered
                // page or converted TIFF/HEIC is already upright.
                $orientation = 1;
                if ($rendered === null && $type === IMAGETYPE_JPEG && exif_imagetype($imagePath) === IMAGETYPE_JPEG) {
                    $exif = exif_read_data($imagePath, 'IFD0', true);
                    $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
                    unset($exif);
                }
                $source = match ($type) {
                    IMAGETYPE_JPEG => imagecreatefromjpeg($imagePath),
                    IMAGETYPE_PNG => imagecreatefrompng($imagePath),
                    IMAGETYPE_WEBP => imagecreatefromwebp($imagePath),
                    IMAGETYPE_BMP => imagecreatefrombmp($imagePath),
                };
                if ($source === false) {
                    throw new UnreadableImage(__('shared.generated.t_7f3c2eb3b57d427d'));
                }
            } finally {
                restore_error_handler();
            }
        } finally {
            if ($rendered !== null && is_file($rendered)) {
                unlink($rendered);
            }
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($source, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
        };
        if ($angle !== 0) {
            $rotated = imagerotate($source, $angle, 0);
            if ($rotated === false) {
                throw new UnreadableImage(__('shared.generated.t_46e60e460378face'));
            }
            $source = $rotated;
        }
        if ($originalSize !== null) {
            ['width' => $width, 'height' => $height] = $originalSize;
        }

        return new DecodedImage(
            image: $source,
            type: $type,
            mediaType: $sourceType,
            width: $width,
            height: $height,
            orientation: $orientation,
            displayWidth: imagesx($source),
            displayHeight: imagesy($source),
            pdfPages: $pdfPages,
        );
    }

    /**
     * Encodes the JPEG previews (quality 85, white background) of an upright image.
     *
     * @return array<int, string> long-side limit => JPEG bytes
     */
    public function previews(GdImage $source): array
    {
        $displayWidth = imagesx($source);
        $displayHeight = imagesy($source);
        $previews = [];
        foreach (self::PREVIEW_LIMITS as $limit) {
            $ratio = min(1, $limit / max($displayWidth, $displayHeight));
            $targetW = max(1, (int) round($displayWidth * $ratio));
            $targetH = max(1, (int) round($displayHeight * $ratio));
            $target = imagecreatetruecolor($targetW, $targetH);
            if ($target === false) {
                throw new RuntimeException(__('shared.generated.t_a3bee48f77a8380c'));
            }
            imagefill($target, 0, 0, 16777215);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $displayWidth, $displayHeight);
            ob_start();
            try {
                if (! imagejpeg($target, null, 85)) {
                    throw new RuntimeException(__('shared.generated.t_e1dcbebc736a4b37'));
                }
                $encoded = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            unset($target);
            if ($encoded === false) {
                throw new RuntimeException(__('shared.generated.t_42bc35733ae3201a'));
            }
            $previews[$limit] = $encoded;
        }

        return $previews;
    }
}
