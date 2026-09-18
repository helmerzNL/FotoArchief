<?php

declare(strict_types=1);

use App\Modules\Ingest\Controllers\UploadSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:assets.view', 'can:assets.create'])->prefix('admin/uploads')->name('admin.uploads.')->group(function (): void {
    Route::get('/', [UploadSessionController::class, 'index'])->name('index');
    Route::post('/', [UploadSessionController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/{session}', [UploadSessionController::class, 'show'])->name('show');
    Route::post('/{session}/close', [UploadSessionController::class, 'close'])->name('close');
    Route::post('/{session}/items/{item}/chunk', [UploadSessionController::class, 'chunk'])->middleware('throttle:600,1')->name('chunk');
    Route::post('/{session}/items/{item}/finalize', [UploadSessionController::class, 'finalize'])->name('finalize');
    Route::post('/{session}/items/{item}/retry', [UploadSessionController::class, 'retry'])->name('retry');
});
