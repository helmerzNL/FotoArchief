<?php

declare(strict_types=1);

use App\Http\Controllers\IdentityInvitationController;
use App\Http\Controllers\IdentityPasskeyController;
use App\Http\Controllers\IdentityPreferencesController;
use App\Http\Controllers\IdentityRecoveryCodeController;
use App\Http\Controllers\IdentitySecurityController;
use App\Http\Controllers\IdentityUserController;
use Illuminate\Support\Facades\Route;

// Invitation and reset links (App-Guidance user-management.md): public, the token is the
// credential, and it never appears in a path or query string.
Route::middleware('throttle:60,1')->prefix('uitnodiging')->name('identity.invitations.')->group(function (): void {
    Route::get('/', [IdentityInvitationController::class, 'show'])->name('show');
    Route::get('/gegevens', [IdentityInvitationController::class, 'info'])->name('info');
    Route::post('/wachtwoord', [IdentityInvitationController::class, 'password'])->name('password');
    Route::post('/passkey/opties', [IdentityInvitationController::class, 'passkeyOptions'])->name('passkey.options');
    Route::post('/passkey', [IdentityInvitationController::class, 'passkeyStore'])->name('passkey.store');
});
Route::get('/uitnodigingen/{token}', [IdentityInvitationController::class, 'legacy'])->middleware('throttle:60,1')->name('identity.invitations.legacy');
Route::post('/passkeys/login/options', [IdentityPasskeyController::class, 'loginOptions'])->middleware('guest')->name('identity.passkeys.login.options');
Route::post('/passkeys/login', [IdentityPasskeyController::class, 'login'])->middleware('guest')->name('identity.passkeys.login');
Route::post('/herstelcodes/login', [IdentityRecoveryCodeController::class, 'login'])->middleware('guest')->name('identity.recovery.login');

Route::middleware('auth')->prefix('admin/identiteit')->name('identity.')->group(function (): void {
    Route::get('/beveiliging', [IdentitySecurityController::class, 'show'])->name('security.show');
    Route::put('/voorkeuren', [IdentityPreferencesController::class, 'update'])->middleware('throttle:60,1')->name('preferences.update');
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
    Route::post('/gebruikers/{user}/herstellink', [IdentityInvitationController::class, 'reset'])->name('users.reset');
    Route::delete('/uitnodigingen/{invitation}', [IdentityInvitationController::class, 'revoke'])->name('invitations.revoke');
});
