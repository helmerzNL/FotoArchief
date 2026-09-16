<?php

declare(strict_types=1);

use App\Modules\ArchiveOperations\Controllers\DiagnosticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin/operations')->name('admin.operations.')->group(function (): void {
    Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->name('diagnostics');
});
