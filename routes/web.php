<?php

declare(strict_types=1);

use App\Http\Controllers\AdminAssetController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// The public homepage is defined in routes/portal.php (public.home).
Route::get('/setup', [InstallationController::class, 'show']);
Route::post('/setup/unlock', [InstallationController::class, 'unlock']);
Route::post('/setup/check', [InstallationController::class, 'check']);
Route::post('/setup/complete', [InstallationController::class, 'complete']);
Route::view('/login', 'auth.login')->name('login');
Route::post('/login', [SessionController::class, 'store']);
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth');
Route::view('/admin', 'admin.dashboard')->middleware(['auth', 'can:users.manage']);
require __DIR__.'/identity.php';
require __DIR__.'/exchange.php';
require __DIR__.'/uploads.php';
Route::middleware(['auth', 'can:assets.view'])->prefix('admin/assets')->name('admin.assets.')->group(function (): void {
    Route::get('/', [AdminAssetController::class, 'index'])->name('index');
    Route::post('/', [AdminAssetController::class, 'store'])->name('store');
    Route::get('/{asset}', [AdminAssetController::class, 'show'])->name('show');
    Route::put('/{asset}', [AdminAssetController::class, 'update'])->name('update');
    Route::get('/{asset}/files/{file}/media/{size}', [AdminAssetController::class, 'media'])->whereIn('size', ['preview300', 'preview1200', 'preview2000'])->name('media');
    Route::post('/{asset}/uploads/{upload}/retry', [AdminAssetController::class, 'retry'])->name('retry');
});

require __DIR__.'/catalogue.php';
require __DIR__.'/operations.php';
require __DIR__.'/portal.php';
