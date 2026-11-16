<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Support;

use GdImage;

/**
 * An original file decoded into an upright GD image, with the facts both ingest and
 * preview regeneration record about it.
 */
final readonly class DecodedImage
{
    /**
     * @param  int  $type  IMAGETYPE_* of the image GD decoded (a rendered PDF page or converted TIFF/HEIC is JPEG)
     * @param  string|null  $mediaType  media type of the original when it is not GD-native (PDF, TIFF, HEIC)
     * @param  int  $width  pixel width of the original
     * @param  int  $height  pixel height of the original
     * @param  int|null  $pdfPages  page count when the original is a PDF
     */
    public function __construct(
        public GdImage $image,
        public int $type,
        public ?string $mediaType,
        public int $width,
        public int $height,
        public int $orientation,
        public int $displayWidth,
        public int $displayHeight,
        public ?int $pdfPages,
    ) {}
}
