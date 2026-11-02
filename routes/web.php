<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use Illuminate\Support\Facades\Route;

// No public welcome page: guests land on the login page through the `auth` middleware.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('auth.oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('auth.oidc.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Users and groups are created and edited in drawers on their list pages.
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('groups', UserGroupController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
        ->middleware('throttle:6,1')
        ->name('users.password-reset');

    // Access settings, as in externa-core: roles live under /settings.
    Route::prefix('settings')->group(function () {
        Route::resource('roles', RoleController::class)->except('show');
    });
});

require __DIR__.'/settings.php';
