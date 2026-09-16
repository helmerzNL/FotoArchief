<?php

declare(strict_types=1);

use App\Modules\Catalogue\Controllers\CatalogueDashboardController;
use App\Modules\Catalogue\Controllers\CollectionController;
use App\Modules\Catalogue\Controllers\LocationController;
use App\Modules\Catalogue\Controllers\PersonController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:assets.view'])->prefix('admin/catalogue')->name('catalogue.')->group(function (): void {
    Route::get('/', [CatalogueDashboardController::class, 'index'])->name('index');

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
});
