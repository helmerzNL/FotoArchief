<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
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
        $file = $asset->files->firstWhere('ingest_status', 'ready_private');

        return view('public.photo', compact('publication', 'asset', 'file'));
    }

    public function media(Request $request, Publication $publication, string $size): StreamedResponse
    {
        abort_unless(in_array($size, self::SIZES, true), 404);
        abort_unless($publication->download_policy !== 'none' || $size === 'preview300', 403, 'Downloaden is niet toegestaan voor deze foto.');
        $asset = $publication->asset()->firstOrFail();
        $file = $asset->files()->where('ingest_status', 'ready_private')->where('scanner_status', 'clean')->first();
        abort_unless($file !== null && $file->storage_disk !== null, 404);
        $key = $file->derivatives[$size] ?? null;
        abort_unless(is_string($key), 404);
        $stream = Storage::disk($file->storage_disk)->readStream($key);
        abort_unless(is_resource($stream), 503, 'Voorbeeld tijdelijk niet beschikbaar.');

        // Revocation must take effect immediately, so responses are never
        // cached by a shared proxy or browser.
        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }
}
