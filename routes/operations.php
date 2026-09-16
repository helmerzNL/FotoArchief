<?php

declare(strict_types=1);

use App\Modules\ArchiveOperations\Controllers\DiagnosticsController;
use App\Modules\ArchiveOperations\Controllers\DuplicateDossierController;
use App\Modules\ArchiveOperations\Controllers\FileVersionController;
use App\Modules\ArchiveOperations\Controllers\IntegrityCheckController;
use App\Modules\ArchiveOperations\Controllers\ProcessingCentreController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin/operations')->name('admin.operations.')->group(function (): void {
    Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->name('diagnostics');

    Route::prefix('duplicates')->name('duplicates.')->group(function (): void {
        Route::get('/', [DuplicateDossierController::class, 'index'])->name('index');
        Route::get('/{upload}', [DuplicateDossierController::class, 'show'])->name('show');
        Route::post('/{upload}/link', [DuplicateDossierController::class, 'link'])->name('link');
    });

    Route::prefix('assets/{asset}/versions')->name('versions.')->group(function (): void {
        Route::get('/', [FileVersionController::class, 'index'])->name('index');
        Route::post('/', [FileVersionController::class, 'store'])->name('store');
        Route::post('/{file}/reprocess', [FileVersionController::class, 'reprocess'])->name('reprocess');
        Route::post('/{file}/set-active', [FileVersionController::class, 'setActive'])->name('setActive');
    });

    Route::prefix('processing')->name('processing.')->group(function (): void {
        Route::get('/', [ProcessingCentreController::class, 'index'])->name('index');
        Route::post('/retry-all', [ProcessingCentreController::class, 'retryAll'])->name('retryAll');
        Route::get('/{upload}', [ProcessingCentreController::class, 'show'])->name('show');
        Route::post('/{upload}/retry', [ProcessingCentreController::class, 'retry'])->name('retry');
        Route::post('/{upload}/cancel', [ProcessingCentreController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('integrity')->name('integrity.')->group(function (): void {
        Route::get('/', [IntegrityCheckController::class, 'index'])->name('index');
        Route::post('/run', [IntegrityCheckController::class, 'runCheck'])->name('run');
        Route::post('/rebuild-all', [IntegrityCheckController::class, 'rebuildAll'])->name('rebuildAll');
        Route::post('/{file}/rebuild', [IntegrityCheckController::class, 'rebuild'])->name('rebuild');
    });
});
