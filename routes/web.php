<?php

use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use Illuminate\Support\Facades\Route;

// No public welcome page: guests land on the login page through the `auth` middleware.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::resource('roles', RoleController::class)->except('show');
    Route::resource('users', UserController::class)->except('show');
    Route::resource('groups', UserGroupController::class)->except('show');
    Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
        ->middleware('throttle:6,1')
        ->name('users.password-reset');
});

require __DIR__.'/settings.php';
