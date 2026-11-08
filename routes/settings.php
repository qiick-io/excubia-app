<?php

use App\Http\Controllers\Settings\AiPricesSettingsController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\AlertSubscriptionController;
use App\Http\Controllers\Settings\AppearanceSettingsController;
use App\Http\Controllers\Settings\AppearanceSidebarSettingsController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\PerformanceSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ProjectIntegrationsSettingsController;
use App\Http\Controllers\Settings\ProjectLocalizationSettingsController;
use App\Http\Controllers\Settings\ProjectSecuritySettingsController;
use App\Http\Controllers\Settings\ProjectSettingsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Models\Setting;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/locale', [LocaleController::class, 'update'])->name('locale.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Alert channels and default severities (#22), behind `can-manage-alerts` (SettingPolicy).
    Route::middleware('can:manageAlerts,'.Setting::class)->group(function () {
        Route::get('settings/project/integrations', [ProjectIntegrationsSettingsController::class, 'edit'])->name('project.integrations.edit');
        Route::put('settings/project/integrations', [ProjectIntegrationsSettingsController::class, 'update'])->name('project.integrations.update');
        Route::post('settings/project/integrations/test/{channel}', [ProjectIntegrationsSettingsController::class, 'sendTest'])
            ->whereIn('channel', ['webhook', 'slack', 'telegram'])
            ->middleware('throttle:10,1')
            ->name('project.integrations.test');

        // AI price table (#129), which the AI budgets and alerts use.
        Route::get('settings/ai-prices', [AiPricesSettingsController::class, 'edit'])->name('ai-prices.edit');
        Route::put('settings/ai-prices', [AiPricesSettingsController::class, 'update'])->name('ai-prices.update');
    });

    // Alert emails of the signed-in user (#22): their own filters and a test mail to themselves.
    Route::put('settings/profile/alerts', [AlertSubscriptionController::class, 'update'])->name('profile.alerts.update');
    Route::post('settings/profile/alerts/test', [AlertSubscriptionController::class, 'sendTest'])
        ->middleware('throttle:10,1')
        ->name('profile.alerts.test');

    // Project settings, as in externa-core, behind `can-manage-project-settings` (SettingPolicy).
    Route::middleware('can:update,'.Setting::class)->group(function () {
        Route::get('settings/project', [ProjectSettingsController::class, 'edit'])->name('project.edit');
        Route::put('settings/project', [ProjectSettingsController::class, 'update'])->name('project.update');

        Route::get('settings/project/localization', [ProjectLocalizationSettingsController::class, 'edit'])->name('project.localization.edit');
        Route::put('settings/project/localization', [ProjectLocalizationSettingsController::class, 'update'])->name('project.localization.update');

        Route::get('settings/project/security', [ProjectSecuritySettingsController::class, 'edit'])->name('project.security.edit');
        Route::put('settings/project/security', [ProjectSecuritySettingsController::class, 'update'])->name('project.security.update');

        Route::get('settings/appearance', [AppearanceSettingsController::class, 'edit'])->name('appearance.edit');
        Route::put('settings/appearance', [AppearanceSettingsController::class, 'update'])->name('appearance.update');

        Route::get('settings/appearance/sidebar', [AppearanceSidebarSettingsController::class, 'edit'])->name('appearance.sidebar.edit');
        Route::put('settings/appearance/sidebar', [AppearanceSidebarSettingsController::class, 'update'])->name('appearance.sidebar.update');

        Route::get('settings/ai', [AiSettingsController::class, 'edit'])->name('ai-settings.edit');
        Route::put('settings/ai', [AiSettingsController::class, 'update'])->name('ai-settings.update');

        Route::get('settings/performance', [PerformanceSettingsController::class, 'edit'])->name('performance.edit');
        Route::post('settings/performance/flush-spatie-permissions', [PerformanceSettingsController::class, 'flushSpatiePermissions'])
            ->name('performance.flush-spatie-permissions');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
