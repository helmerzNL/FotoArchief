<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetRight;
use App\Modules\Publication\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public photo viewer. {@see Publication::resolveRouteBinding()} already
 * rejects private, embargoed or revoked publications with a 404 before any
 * action here runs, so both routes only ever see eligible photos.
 */
class PublicPhotoController extends Controller
{
    private const SIZES = ['preview300', 'preview1200', 'preview2000'];

    public function show(Request $request, Publication $publication): View
    {
        $asset = $publication->asset()->with(['files', 'rights.license', 'rights.rightsStatement', 'tags'])->firstOrFail();
        // Same canonical file every other public route agrees on (see
        // Asset::currentPublicFile()); fail closed with a 404 rather than
        // render a page around an ambiguous or missing file.
        $file = $asset->currentPublicFile();
        abort_unless($file !== null, 404);
        $right = $asset->rights->firstWhere('verification_status', 'verified');
        $canonicalUrl = route('public.photo', $publication);
        $structuredData = $this->structuredData($publication, $asset, $file, $right, $canonicalUrl);

        return view('public.photo', compact('publication', 'asset', 'file', 'right', 'canonicalUrl', 'structuredData'));
    }

    public function media(Request $request, Publication $publication, string $size): StreamedResponse
    {
        abort_unless(in_array($size, self::SIZES, true), 404);
        $download = $request->boolean('download');
        abort_unless(! $download || ($publication->download_policy === 'preview_only' && $size === 'preview2000'), 403, 'Downloaden is niet toegestaan voor deze foto.');
        // Same canonical file the predicate and viewer already agreed on
        // (see Asset::currentPublicFile()) - never re-derive eligibility
        // independently here, or the media stream could diverge from what
        // the viewer just showed.
        $asset = $publication->asset()->with('files')->firstOrFail();
        $file = $asset->currentPublicFile();
        abort_unless($file !== null && $file->storage_disk !== null, 404);
        $key = $file->derivatives[$size] ?? null;
        abort_unless(is_string($key), 404);
        $stream = Storage::disk($file->storage_disk)->readStream($key);
        abort_unless(is_resource($stream), 503, 'Voorbeeld tijdelijk niet beschikbaar.');
        $headers = ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private'];
        if ($download) {
            $headers['Content-Disposition'] = 'attachment; filename="'.($publication->permalink_slug ?? 'foto').'.jpg"';
        }

        // Revocation must take effect immediately, so responses are never
        // cached by a shared proxy or browser.
        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredData(Publication $publication, Asset $asset, ?AssetFile $file, ?AssetRight $right, string $canonicalUrl): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Photograph',
            'name' => $asset->title ?: $asset->accession_number,
            'url' => $canonicalUrl,
            'identifier' => $asset->accession_number,
        ];
        if ($asset->description) {
            $data['description'] = $asset->description;
        }
        if ($file !== null) {
            $data['contentUrl'] = route('public.photo.media', [$publication, 'preview1200']);
            $data['thumbnailUrl'] = route('public.photo.media', [$publication, 'preview300']);
        }
        if ($publication->credit_line) {
            $data['creditText'] = $publication->credit_line;
        }
        if ($right?->rights_holder) {
            $data['copyrightHolder'] = ['@type' => 'Organization', 'name' => $right->rights_holder];
        }
        if ($right?->license?->url) {
            $data['license'] = $right->license->url;
        }
        if ($asset->date_display) {
            $data['dateCreated'] = $asset->date_display;
        }

        return $data;
    }
}
