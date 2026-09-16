<?php

declare(strict_types=1);

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
