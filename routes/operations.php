<?php

declare(strict_types=1);

use App\Modules\Ai\Controllers\AiSemanticSearchController;
use App\Modules\Ai\Controllers\AiSettingsController;
use App\Modules\Ai\Controllers\AiSuggestionReviewController;
use App\Modules\ArchiveOperations\Controllers\DiagnosticsController;
use App\Modules\ArchiveOperations\Controllers\DuplicateDossierController;
use App\Modules\ArchiveOperations\Controllers\FileVersionController;
use App\Modules\ArchiveOperations\Controllers\IntegrityCheckController;
use App\Modules\ArchiveOperations\Controllers\OcrController;
use App\Modules\ArchiveOperations\Controllers\OperationRunController;
use App\Modules\ArchiveOperations\Controllers\OperationsLandingController;
use App\Modules\ArchiveOperations\Controllers\ProcessingCentreController;
use App\Modules\ArchiveOperations\Controllers\StorageMigrationController;
use App\Modules\ArchiveOperations\Controllers\TrashController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin/operations')->name('admin.operations.')->group(function (): void {
    Route::get('/', [OperationsLandingController::class, 'index'])->name('index');
    Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->name('diagnostics');
    Route::get('/ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
    Route::post('/ai', [AiSettingsController::class, 'update'])->name('ai.update');
    Route::post('/ai/analyze', [AiSettingsController::class, 'dispatchAnalysis'])->name('ai.analyze');
    Route::post('/ai/index', [AiSettingsController::class, 'dispatchIndex'])->name('ai.index');
    Route::post('/ai/test-connection', [AiSettingsController::class, 'testConnection'])->name('ai.test-connection');
    Route::post('/ai/providers/{provider}', [AiSettingsController::class, 'updateProvider'])->name('ai.provider.update');
    Route::post('/ai/providers/{provider}/key', [AiSettingsController::class, 'setProviderKey'])->name('ai.provider.key.set');
    Route::delete('/ai/providers/{provider}/key', [AiSettingsController::class, 'deleteProviderKey'])->name('ai.provider.key.delete');
    Route::get('/ai/search', AiSemanticSearchController::class)->name('ai.search');
    Route::get('/ai/suggestions', [AiSuggestionReviewController::class, 'index'])->name('ai.suggestions.index');
    Route::post('/ai/suggestions/{suggestion}/accept', [AiSuggestionReviewController::class, 'accept'])->name('ai.suggestions.accept');
    Route::post('/ai/suggestions/{suggestion}/reject', [AiSuggestionReviewController::class, 'reject'])->name('ai.suggestions.reject');

    Route::prefix('runs')->name('runs.')->group(function (): void {
        Route::get('/', [OperationRunController::class, 'index'])->name('index');
        Route::post('/{run}/retry', [OperationRunController::class, 'retry'])->name('retry');
        Route::post('/{run}/cancel', [OperationRunController::class, 'cancel'])->name('cancel');
    });

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

    Route::prefix('storage-migration')->name('storage.')->group(function (): void {
        Route::get('/', [StorageMigrationController::class, 'index'])->name('index');
        Route::post('/start', [StorageMigrationController::class, 'start'])->name('start');
        Route::post('/{migration}/cutover', [StorageMigrationController::class, 'cutover'])->name('cutover');
        Route::post('/{migration}/cleanup', [StorageMigrationController::class, 'cleanup'])->name('cleanup');
    });

    Route::prefix('trash')->name('trash.')->group(function (): void {
        Route::get('/', [TrashController::class, 'index'])->name('index');
        Route::post('/assets/{asset}/trash', [TrashController::class, 'trash'])->name('trash');
        Route::post('/assets/{asset}/restore', [TrashController::class, 'restore'])->name('restore');
        Route::delete('/assets/{asset}/purge', [TrashController::class, 'purge'])->name('purge');
        Route::post('/purge-expired', [TrashController::class, 'purgeExpired'])->name('purgeExpired');
        Route::post('/cleanup-orphans', [TrashController::class, 'cleanupOrphans'])->name('cleanupOrphans');
    });

    Route::prefix('ocr')->name('ocr.')->group(function (): void {
        Route::get('/', [OcrController::class, 'index'])->name('index');
        Route::get('/{ocr}', [OcrController::class, 'show'])->name('show');
        Route::post('/{ocr}', [OcrController::class, 'update'])->name('update');
        Route::post('/assets/{asset}/dispatch', [OcrController::class, 'dispatchOcr'])->name('dispatch');
    });
});
