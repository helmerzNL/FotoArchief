<?php

declare(strict_types=1);

use App\Modules\DataExchange\Http\Controllers\ExchangeController;
use App\Modules\DataExchange\Http\Controllers\MetadataImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:assets.view'])->prefix('exchange')->name('exchange.')->group(function (): void {
    Route::get('/', [ExchangeController::class, 'index'])->name('index');
    Route::post('/imports', [MetadataImportController::class, 'store'])->name('imports.store');
    Route::get('/imports/{import}', [MetadataImportController::class, 'show'])->name('imports.show');
    Route::post('/imports/{import}/analyse', [MetadataImportController::class, 'analyse'])->name('imports.analyse');
    Route::post('/imports/{import}/confirm', [MetadataImportController::class, 'confirm'])->name('imports.confirm');
});
