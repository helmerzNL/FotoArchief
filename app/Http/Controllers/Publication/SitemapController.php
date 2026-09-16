<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Modules\Publication\Models\Publication;
use Illuminate\Http\Response;

/**
 * Segmented XML sitemaps over the same publiclyVisible() predicate as every
 * other public route. Unlike the interactive search page, a sitemap is
 * fetched by crawlers in fixed, numbered pages rather than an infinite
 * scroll, so this uses plain LIMIT/OFFSET chunking (bounded to a modest page
 * size) instead of a keyset cursor; that trade-off is intentional here and
 * does not apply to user-facing listings.
 */
class SitemapController extends Controller
{
    private const PER_PAGE = 5000;

    public function index(): Response
    {
        $total = Publication::query()->publiclyVisible()->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $entries = collect(range(1, $pages))->map(fn (int $page) => route('public.sitemap.photos', $page));
        $xml = view('public.sitemaps.index', compact('entries'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    public function photos(int $page): Response
    {
        abort_if($page < 1, 404);
        $publications = Publication::query()->publiclyVisible()
            ->orderBy('published_at')->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->get();
        abort_if($publications->isEmpty(), 404);
        $xml = view('public.sitemaps.photos', compact('publications'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
