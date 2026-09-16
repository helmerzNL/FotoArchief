<?php

declare(strict_types=1);

use App\Http\Controllers\Publication\PublicDiscoveryController;
use App\Http\Controllers\Publication\PublicPhotoController;
use App\Http\Controllers\Publication\PublicSuggestionController;
use App\Http\Controllers\Publication\SitemapController;
use App\Http\Controllers\Publication\StaffPublicationController;
use App\Http\Controllers\Publication\StaffSuggestionController;
use Illuminate\Support\Facades\Route;

// Staff publication workflow: draft/review/published/revoked (step 16).
Route::middleware(['auth', 'can:assets.view'])->prefix('admin/publications')->name('admin.publications.')->group(function (): void {
    Route::get('/', [StaffPublicationController::class, 'index'])->name('index');
    Route::get('/{asset}', [StaffPublicationController::class, 'show'])->name('show');
    Route::post('/{asset}/submit', [StaffPublicationController::class, 'submit'])->name('submit');
    Route::post('/{asset}/publish', [StaffPublicationController::class, 'publish'])->name('publish');
    Route::post('/{asset}/reject', [StaffPublicationController::class, 'reject'])->name('reject');
    Route::post('/{asset}/revoke', [StaffPublicationController::class, 'revoke'])->name('revoke');
});

// Staff moderation of visitor suggestions (step 19). Accept/reject only
// records the decision; it never mutates asset metadata (see controller).
Route::middleware(['auth', 'can:assets.view'])->prefix('admin/suggesties')->name('admin.suggestions.')->group(function (): void {
    Route::get('/', [StaffSuggestionController::class, 'index'])->name('index');
    Route::get('/{suggestion}', [StaffSuggestionController::class, 'show'])->name('show');
    Route::post('/{suggestion}/accept', [StaffSuggestionController::class, 'accept'])->name('accept');
    Route::post('/{suggestion}/reject', [StaffSuggestionController::class, 'reject'])->name('reject');
});

// Public search, collections and the photo viewer only ever read through
// Publication::publiclyVisible() (step 17/18): private, embargoed, revoked
// or unscanned assets 404 instead of rendering.
Route::get('/', [PublicDiscoveryController::class, 'search'])->name('public.home');
Route::get('/ontdek', [PublicDiscoveryController::class, 'search'])->name('public.discover');
Route::get('/collecties', [PublicDiscoveryController::class, 'collections'])->name('public.collections.index');
Route::get('/collecties/{collection}', [PublicDiscoveryController::class, 'collectionShow'])->name('public.collections.show');
Route::get('/foto/{publication}', [PublicPhotoController::class, 'show'])->name('public.photo');
Route::get('/foto/{publication}/media/{size}', [PublicPhotoController::class, 'media'])->whereIn('size', ['preview300', 'preview1200', 'preview2000'])->name('public.photo.media');

// Anonymous correction/identification suggestions (step 19). Rate-limited to
// keep an anonymous form from being abused; validation also bounds message
// length and rejects a filled honeypot field.
Route::post('/foto/{publication}/suggesties', [PublicSuggestionController::class, 'store'])->middleware('throttle:5,60')->name('public.photo.suggest');

// Segmented sitemaps (step 18); each page is bounded (LIMIT/OFFSET, see
// SitemapController) so crawling stays cheap even at 50k+ assets.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('public.sitemap.index');
Route::get('/sitemap-fotos-{page}.xml', [SitemapController::class, 'photos'])->whereNumber('page')->name('public.sitemap.photos');
