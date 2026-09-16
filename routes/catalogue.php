<?php

declare(strict_types=1);

use App\Modules\Catalogue\Controllers\CatalogueDashboardController;
use App\Modules\Catalogue\Controllers\CollectionController;
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
});
