<?php

declare(strict_types=1);

use App\Modules\ArchiveOperations\Controllers\DiagnosticsController;
use App\Modules\ArchiveOperations\Controllers\DuplicateDossierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin/operations')->name('admin.operations.')->group(function (): void {
    Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->name('diagnostics');

    Route::prefix('duplicates')->name('duplicates.')->group(function (): void {
        Route::get('/', [DuplicateDossierController::class, 'index'])->name('index');
        Route::get('/{upload}', [DuplicateDossierController::class, 'show'])->name('show');
        Route::post('/{upload}/link', [DuplicateDossierController::class, 'link'])->name('link');
    });
});
