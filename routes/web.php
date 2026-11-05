<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JobMonitorController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Route;

// No public welcome page: guests land on the login page through the `auth` middleware.
Route::redirect('/', '/dashboard')->name('home');

// Uploaded logo and favicon (Settings → Appearance), public for the login page.
Route::get('branding/{asset}', [BrandingController::class, 'show'])
    ->whereIn('asset', array_keys(SettingsRepositoryService::BRANDING_ASSETS))
    ->name('branding.show');

Route::middleware('guest')->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('auth.oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('auth.oidc.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Projects are created and edited in a drawer on the list page.
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('projects/{project:slug}', [ProjectReportController::class, 'show'])->name('projects.show');
    // The project's tabs as their own URLs, so links, back and bookmarks work.
    Route::get('projects/{project:slug}/{view}', [ProjectReportController::class, 'show'])
        ->whereIn('view', ProjectReportController::VIEWS)
        ->name('projects.view');
    Route::post('projects/{project}/token', [ProjectController::class, 'rotateToken'])->name('projects.token.rotate');
    Route::delete('projects/{project}/token', [ProjectController::class, 'revokeToken'])->name('projects.token.revoke');
    Route::post('projects/{project}/system-report', [ProjectReportController::class, 'refresh'])->name('projects.system-report');
    Route::patch('projects/{project}/exceptions/{exceptionGroup}', [ProjectReportController::class, 'updateException'])->name('projects.exceptions.update');

    // AI assistant (#17) at /ai, as in externa-core: 404 while `excubia.ai_enabled` is off.
    Route::get('ai', [AssistantController::class, 'index'])->name('ai.index');
    Route::post('ai', [AssistantController::class, 'store'])->middleware('throttle:20,1')->name('ai.store');
    Route::delete('ai/conversations', [AssistantController::class, 'destroy'])->name('ai.conversations.destroy');
    Route::get('ai/{conversation}', [AssistantController::class, 'show'])->name('ai.show');
    // Links from before the move to /ai.
    Route::redirect('assistant', '/ai');
    Route::get('assistant/{conversation}', fn (string $conversation) => redirect()->route('ai.show', $conversation));

    Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

    // Users and groups are created and edited in drawers on their list pages.
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('groups', UserGroupController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
        ->middleware('throttle:6,1')
        ->name('users.password-reset');

    // Access settings, as in externa-core: roles, permissions and jobs live under /settings.
    Route::prefix('settings')->group(function () {
        Route::resource('roles', RoleController::class)->except('show');

        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions/sync', [PermissionController::class, 'sync'])->name('permissions.sync');

        Route::get('jobs', [JobMonitorController::class, 'index'])->name('jobs.index');
        Route::post('jobs/{uuid}/retry', [JobMonitorController::class, 'retry'])->whereUuid('uuid')->name('jobs.retry');
        Route::delete('jobs/{uuid}', [JobMonitorController::class, 'destroy'])->whereUuid('uuid')->name('jobs.destroy');
    });
});

require __DIR__.'/settings.php';
