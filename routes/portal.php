<?php

declare(strict_types=1);

use App\Http\Controllers\Publication\PublicDiscoveryController;
use App\Http\Controllers\Publication\PublicPhotoController;
use App\Http\Controllers\Publication\StaffPublicationController;
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

// Public search, collections and the photo viewer only ever read through
// Publication::publiclyVisible() (step 17/18): private, embargoed, revoked
// or unscanned assets 404 instead of rendering.
Route::get('/ontdek', [PublicDiscoveryController::class, 'search'])->name('public.discover');
Route::get('/collecties', [PublicDiscoveryController::class, 'collections'])->name('public.collections.index');
Route::get('/collecties/{collection}', [PublicDiscoveryController::class, 'collectionShow'])->name('public.collections.show');
Route::get('/foto/{publication}', [PublicPhotoController::class, 'show'])->name('public.photo');
Route::get('/foto/{publication}/media/{size}', [PublicPhotoController::class, 'media'])->whereIn('size', ['preview300', 'preview1200', 'preview2000'])->name('public.photo.media');
