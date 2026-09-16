<?php

declare(strict_types=1);

use App\Http\Controllers\IdentityInvitationController;
use App\Http\Controllers\IdentityUserController;
use Illuminate\Support\Facades\Route;

Route::get('/uitnodigingen/{token}', [IdentityInvitationController::class, 'accept'])->name('identity.invitations.accept');
Route::post('/uitnodigingen/{token}', [IdentityInvitationController::class, 'complete'])->name('identity.invitations.complete');

Route::middleware(['auth', 'can:users.manage'])->prefix('admin/identiteit')->name('identity.')->group(function (): void {
    Route::get('/', [IdentityUserController::class, 'index'])->name('users.index');
    Route::get('/uitnodigen', [IdentityInvitationController::class, 'create'])->name('invitations.create');
    Route::post('/uitnodigen', [IdentityInvitationController::class, 'store'])->name('invitations.store');
    Route::put('/gebruikers/{user}', [IdentityUserController::class, 'update'])->name('users.update');
    Route::post('/gebruikers/{user}/deactiveren', [IdentityUserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/gebruikers/{user}/heractiveren', [IdentityUserController::class, 'reactivate'])->name('users.reactivate');
});
