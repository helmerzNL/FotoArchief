<?php

declare(strict_types=1);

use App\Http\Controllers\IdentityInvitationController;
use App\Http\Controllers\IdentityPasskeyController;
use App\Http\Controllers\IdentityRecoveryCodeController;
use App\Http\Controllers\IdentitySecurityController;
use App\Http\Controllers\IdentityUserController;
use Illuminate\Support\Facades\Route;

Route::get('/uitnodigingen/{token}', [IdentityInvitationController::class, 'accept'])->name('identity.invitations.accept');
Route::post('/uitnodigingen/{token}', [IdentityInvitationController::class, 'complete'])->name('identity.invitations.complete');
Route::post('/passkeys/login/options', [IdentityPasskeyController::class, 'loginOptions'])->middleware('guest')->name('identity.passkeys.login.options');
Route::post('/passkeys/login', [IdentityPasskeyController::class, 'login'])->middleware('guest')->name('identity.passkeys.login');
Route::post('/herstelcodes/login', [IdentityRecoveryCodeController::class, 'login'])->middleware('guest')->name('identity.recovery.login');

Route::middleware('auth')->prefix('admin/identiteit')->name('identity.')->group(function (): void {
    Route::get('/beveiliging', [IdentitySecurityController::class, 'show'])->name('security.show');
    Route::post('/passkeys/options', [IdentityPasskeyController::class, 'enrollmentOptions'])->name('passkeys.options');
    Route::post('/passkeys', [IdentityPasskeyController::class, 'store'])->name('passkeys.store');
    Route::delete('/passkeys/{passkey}', [IdentityPasskeyController::class, 'destroy'])->name('passkeys.destroy');
    Route::post('/herstelcodes', [IdentityRecoveryCodeController::class, 'regenerate'])->name('recovery.regenerate');
});

Route::middleware(['auth', 'can:users.manage'])->prefix('admin/identiteit')->name('identity.')->group(function (): void {
    Route::get('/', [IdentityUserController::class, 'index'])->name('users.index');
    Route::get('/uitnodigen', [IdentityInvitationController::class, 'create'])->name('invitations.create');
    Route::post('/uitnodigen', [IdentityInvitationController::class, 'store'])->name('invitations.store');
    Route::put('/gebruikers/{user}', [IdentityUserController::class, 'update'])->name('users.update');
    Route::post('/gebruikers/{user}/deactiveren', [IdentityUserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/gebruikers/{user}/heractiveren', [IdentityUserController::class, 'reactivate'])->name('users.reactivate');
});
