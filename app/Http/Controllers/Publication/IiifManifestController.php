<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetRight;
use App\Modules\Publication\Models\Publication;
use Illuminate\Http\JsonResponse;

/**
 * A minimal IIIF Presentation API 3.0 manifest over the existing bounded
 * JPEG derivatives. This deliberately does NOT implement the IIIF Image API
 * (no region/size/rotation/quality/format parameters or a real tile/info.json
 * service): the canvas body is a plain, already-generated derivative image,
 * which any spec-compliant viewer can still render without deep zoom tiling.
 *
 * {@see Publication::resolveRouteBinding()} already rejects private,
 * embargoed or revoked publications with a 404 before this action runs, so
 * a manifest can only ever be produced for a photo the visitor may see.
 */
class IiifManifestController extends Controller
{
    public function manifest(Publication $publication): JsonResponse
    {
        $asset = $publication->asset()->with(['files', 'rights.license', 'rights.rightsStatement'])->firstOrFail();
        // Same canonical file the predicate and viewer already agreed on
        // (see Asset::currentPublicFile()); never re-derive eligibility
        // independently, or the manifest could describe a different,
        // superseded file than the one the viewer shows.
        $file = $asset->currentPublicFile();
        abort_unless($file !== null && is_array($file->derivatives) && isset($file->derivatives['preview2000']), 404);
        $right = $asset->rights->firstWhere('verification_status', 'verified');

        return response()->json($this->buildManifest($publication, $asset, $file, $right))
            ->header('Content-Type', 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(Publication $publication, Asset $asset, AssetFile $file, ?AssetRight $right): array
    {
        $manifestId = route('iiif.manifest', $publication);
        $canvasId = $manifestId.'#canvas';
        $label = $asset->title ?: $asset->accession_number;
        [$width, $height] = $this->derivativeDimensions($file, 'preview2000');
        $imageUrl = route('public.photo.media', [$publication, 'preview2000']);
        $thumbnailUrl = route('public.photo.media', [$publication, 'preview300']);

        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id' => $manifestId,
            'type' => 'Manifest',
            'label' => ['nl' => [$label]],
            'thumbnail' => [[
                'id' => $thumbnailUrl,
                'type' => 'Image',
                'format' => 'image/jpeg',
            ]],
            'items' => [[
                'id' => $canvasId,
                'type' => 'Canvas',
                'width' => $width,
                'height' => $height,
                'items' => [[
                    'id' => $canvasId.'/page',
                    'type' => 'AnnotationPage',
                    'items' => [[
                        'id' => $canvasId.'/annotation',
                        'type' => 'Annotation',
                        'motivation' => 'painting',
                        'body' => [
                            'id' => $imageUrl,
                            'type' => 'Image',
                            'format' => 'image/jpeg',
                            'width' => $width,
                            'height' => $height,
                        ],
                        'target' => $canvasId,
                    ]],
                ]],
            ]],
        ];

        if ($right?->license?->url) {
            $manifest['rights'] = $right->license->url;
        }
        if ($publication->credit_line) {
            $manifest['requiredStatement'] = [
                'label' => ['nl' => ['Bronvermelding']],
                'value' => ['nl' => [$publication->credit_line]],
            ];
        }
        if ($asset->description) {
            $manifest['summary'] = ['nl' => [$asset->description]];
        }

        return $manifest;
    }

    /**
     * The IIIF canvas must describe the *derivative's* pixel size, not the
     * original's. Only the original's post-orientation dimensions are
     * stored, so this mirrors the same min(1, limit/max(w,h)) ratio used
     * when the derivatives were generated (see ImageProcessor).
     *
     * @return array{0: int, 1: int}
     */
    private function derivativeDimensions(AssetFile $file, string $size): array
    {
        $limits = ['preview300' => 300, 'preview1200' => 1200, 'preview2000' => 2000];
        $limit = $limits[$size] ?? 2000;
        $width = (int) ($file->pixel_width ?? 0);
        $height = (int) ($file->pixel_height ?? 0);
        if ($width < 1 || $height < 1) {
            return [$limit, $limit];
        }
        $ratio = min(1, $limit / max($width, $height));

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }
}
