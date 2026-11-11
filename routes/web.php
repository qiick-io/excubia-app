<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\JobMonitorController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProjectAlertsController;
use App\Http\Controllers\ProjectAssigneeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectGroupController;
use App\Http\Controllers\ProjectPinController;
use App\Http\Controllers\ProjectReportController;
use App\Http\Controllers\ProjectTokenController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RoleFormController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\UserPasswordResetController;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Route;

// No public welcome page: guests land on the login page through the `auth` middleware.
Route::redirect('/', '/dashboard')->name('home');

// Uploaded logo and favicon (Settings → Appearance), public for the login page.
Route::get('branding/{asset}', [BrandingController::class, 'show'])
    ->whereIn('asset', array_keys(SettingsRepositoryService::BRANDING_ASSETS))
    ->name('branding.show');

// First-run wizard (#116): the admin step only while no user exists, then the first project.
Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('setup/admin', [SetupController::class, 'storeAdmin'])->middleware('throttle:6,1')->name('setup.admin.store');
Route::post('setup/project', [SetupController::class, 'storeProject'])->middleware(['auth', 'verified'])->name('setup.project.store');

// Each callback makes token and userinfo calls to the IdP (#154).
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('auth.oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('auth.oidc.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // The command palette's server search (#274): JSON, scoped by access in the service.
    Route::get('search', GlobalSearchController::class)->middleware('throttle:60,1')->name('search');

    // Projects are created and edited in a drawer on the list page.
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('project-groups', ProjectGroupController::class)->only(['index', 'store', 'update', 'destroy']);
    // Sidebar pins (#273).
    Route::post('projects/{project:slug}/pin', [ProjectPinController::class, 'store'])->name('projects.pin.store');
    Route::delete('projects/{project:slug}/pin', [ProjectPinController::class, 'destroy'])->name('projects.pin.destroy');
    Route::get('projects/{project:slug}', [ProjectReportController::class, 'show'])->name('projects.show');
    // The project's tabs as their own URLs, so links, back and bookmarks work.
    Route::get('projects/{project:slug}/{view}', [ProjectReportController::class, 'show'])
        ->whereIn('view', ProjectReportController::VIEWS)
        ->name('projects.view');
    Route::post('projects/{project}/token', [ProjectTokenController::class, 'store'])->name('projects.token.rotate');
    Route::delete('projects/{project}/token', [ProjectTokenController::class, 'destroy'])->name('projects.token.revoke');
    Route::post('projects/{project}/system-report', [ProjectReportController::class, 'refresh'])->name('projects.system-report');
    Route::patch('projects/{project}/exceptions/{exceptionGroup}', [ProjectReportController::class, 'updateException'])->name('projects.exceptions.update');
    Route::patch('projects/{project}/findings/{finding}', [ProjectReportController::class, 'updateFinding'])->name('projects.findings.update');
    // Who is on an exception group or a finding (#254).
    Route::put('projects/{project}/exceptions/{exceptionGroup}/assignee', [ProjectAssigneeController::class, 'exception'])->name('projects.exceptions.assignee');
    Route::put('projects/{project}/findings/{finding}/assignee', [ProjectAssigneeController::class, 'finding'])->name('projects.findings.assignee');
    // Alert controls on the project's Alerts tab (#114).
    Route::post('projects/{project}/alert-mutes', [ProjectAlertsController::class, 'storeMute'])->name('projects.alert-mutes.store');
    Route::delete('projects/{project}/alert-mutes/{alertMute}', [ProjectAlertsController::class, 'destroyMute'])->name('projects.alert-mutes.destroy');
    Route::post('projects/{project}/maintenance-windows', [ProjectAlertsController::class, 'storeWindow'])->name('projects.maintenance-windows.store');
    Route::delete('projects/{project}/maintenance-windows/{maintenanceWindow}', [ProjectAlertsController::class, 'destroyWindow'])->name('projects.maintenance-windows.destroy');
    // Monthly AI budget on the project's AI tab (#129).
    Route::put('projects/{project}/ai-budget', [ProjectAlertsController::class, 'updateAiBudget'])->name('projects.ai-budget.update');

    // Fleet views across the visible projects (#19, #20, #23).
    Route::get('fleet', [FleetController::class, 'show'])->name('fleet.show');
    Route::get('fleet/{view}', [FleetController::class, 'show'])
        ->whereIn('view', FleetController::VIEWS)
        ->name('fleet.view');

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
    // The roles the current user may grant, for the role picker of the user and group drawers.
    Route::get('users/assignable-roles', [UserController::class, 'assignableRoles'])->name('users.assignable-roles');
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('groups', UserGroupController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('users/{user}/password-reset', UserPasswordResetController::class)
        ->middleware('throttle:6,1')
        ->name('users.password-reset');

    // Access settings, as in externa-core: roles, permissions and jobs live under /settings.
    Route::prefix('settings')->group(function () {
        Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
        Route::get('roles/create', [RoleFormController::class, 'create'])->name('roles.create');
        Route::get('roles/{role}/edit', [RoleFormController::class, 'edit'])->name('roles.edit');

        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions/sync', [PermissionController::class, 'sync'])->name('permissions.sync');

        Route::get('jobs', [JobMonitorController::class, 'index'])->name('jobs.index');
        Route::post('jobs/{uuid}/retry', [JobMonitorController::class, 'retry'])->whereUuid('uuid')->name('jobs.retry');
        Route::delete('jobs/{uuid}', [JobMonitorController::class, 'destroy'])->whereUuid('uuid')->name('jobs.destroy');

        // Health of this install (#117).
        Route::get('system', [SystemHealthController::class, 'index'])->name('system.index');
    });
});

require __DIR__.'/settings.php';
