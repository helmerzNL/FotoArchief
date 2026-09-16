<?php

declare(strict_types=1);

use App\Modules\Catalogue\Controllers\BulkAssetController;
use App\Modules\Catalogue\Controllers\CatalogueDashboardController;
use App\Modules\Catalogue\Controllers\CollectionController;
use App\Modules\Catalogue\Controllers\ContributorController;
use App\Modules\Catalogue\Controllers\LocationController;
use App\Modules\Catalogue\Controllers\PersonController;
use App\Modules\Catalogue\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:assets.view'])->prefix('admin/catalogue')->name('catalogue.')->group(function (): void {
    Route::get('/', [CatalogueDashboardController::class, 'index'])->name('index');

    // Bulk Operations
    Route::prefix('bulk')->name('bulk.')->group(function (): void {
        Route::get('/confirm', [BulkAssetController::class, 'create'])->name('confirm');
        Route::post('/apply', [BulkAssetController::class, 'store'])->name('apply');
    });

    // Collections & Albums
    Route::prefix('collections')->name('collections.')->group(function (): void {
        Route::get('/', [CollectionController::class, 'index'])->name('index');
        Route::get('/create', [CollectionController::class, 'create'])->name('create');
        Route::post('/', [CollectionController::class, 'store'])->name('store');
        Route::get('/{collection}', [CollectionController::class, 'show'])->name('show');
        Route::get('/{collection}/edit', [CollectionController::class, 'edit'])->name('edit');
        Route::put('/{collection}', [CollectionController::class, 'update'])->name('update');
        Route::delete('/{collection}', [CollectionController::class, 'destroy'])->name('destroy');
        Route::post('/{collection}/assets', [CollectionController::class, 'addAsset'])->name('assets.add');
        Route::delete('/{collection}/assets/{asset}', [CollectionController::class, 'removeAsset'])->name('assets.remove');
        Route::post('/{collection}/assets/{asset}/move', [CollectionController::class, 'moveAsset'])->name('assets.move');
        Route::post('/{collection}/reorder', [CollectionController::class, 'reorder'])->name('reorder');
    });

    // People & Organisations
    Route::prefix('people')->name('people.')->group(function (): void {
        Route::get('/', [PersonController::class, 'index'])->name('index');
        Route::get('/create', [PersonController::class, 'create'])->name('create');
        Route::post('/', [PersonController::class, 'store'])->name('store');
        Route::get('/{person}', [PersonController::class, 'show'])->name('show');
        Route::get('/{person}/edit', [PersonController::class, 'edit'])->name('edit');
        Route::put('/{person}', [PersonController::class, 'update'])->name('update');
        Route::delete('/{person}', [PersonController::class, 'destroy'])->name('destroy');
        Route::post('/{person}/assets', [PersonController::class, 'addAsset'])->name('assets.add');
        Route::delete('/{person}/assets/{asset}', [PersonController::class, 'removeAsset'])->name('assets.remove');
    });

    // Locations
    Route::prefix('locations')->name('locations.')->group(function (): void {
        Route::get('/', [LocationController::class, 'index'])->name('index');
        Route::get('/create', [LocationController::class, 'create'])->name('create');
        Route::post('/', [LocationController::class, 'store'])->name('store');
        Route::get('/{location}', [LocationController::class, 'show'])->name('show');
        Route::get('/{location}/edit', [LocationController::class, 'edit'])->name('edit');
        Route::put('/{location}', [LocationController::class, 'update'])->name('update');
        Route::delete('/{location}', [LocationController::class, 'destroy'])->name('destroy');
        Route::post('/{location}/assets', [LocationController::class, 'addAsset'])->name('assets.add');
        Route::delete('/{location}/assets/{asset}', [LocationController::class, 'removeAsset'])->name('assets.remove');
    });

    // Sources (Provenance)
    Route::prefix('sources')->name('sources.')->group(function (): void {
        Route::get('/', [SourceController::class, 'index'])->name('index');
        Route::get('/create', [SourceController::class, 'create'])->name('create');
        Route::post('/', [SourceController::class, 'store'])->name('store');
        Route::get('/{source}', [SourceController::class, 'show'])->name('show');
        Route::get('/{source}/edit', [SourceController::class, 'edit'])->name('edit');
        Route::put('/{source}', [SourceController::class, 'update'])->name('update');
        Route::delete('/{source}', [SourceController::class, 'destroy'])->name('destroy');
        Route::post('/{source}/assets', [SourceController::class, 'addAsset'])->name('assets.add');
        Route::delete('/{source}/assets/{asset}', [SourceController::class, 'removeAsset'])->name('assets.remove');
    });

    // Contributors (Donors/Photographers)
    Route::prefix('contributors')->name('contributors.')->group(function (): void {
        Route::get('/', [ContributorController::class, 'index'])->name('index');
        Route::get('/create', [ContributorController::class, 'create'])->name('create');
        Route::post('/', [ContributorController::class, 'store'])->name('store');
        Route::get('/{contributor}', [ContributorController::class, 'show'])->name('show');
        Route::get('/{contributor}/edit', [ContributorController::class, 'edit'])->name('edit');
        Route::put('/{contributor}', [ContributorController::class, 'update'])->name('update');
        Route::delete('/{contributor}', [ContributorController::class, 'destroy'])->name('destroy');
        Route::post('/{contributor}/assets', [ContributorController::class, 'addAsset'])->name('assets.add');
        Route::delete('/{contributor}/assets/{asset}', [ContributorController::class, 'removeAsset'])->name('assets.remove');
    });
});
