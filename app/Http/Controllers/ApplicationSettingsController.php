<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateApplicationSettingsRequest;
use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The application settings page, behind `settings.manage` (SettingPolicy).
 */
class ApplicationSettingsController extends Controller
{
    public function edit(SettingsRepositoryService $settings): Response
    {
        Gate::authorize('update', Setting::class);

        return Inertia::render('settings/application', [
            'settings' => $settings->all(),
            'passwordPolicies' => SettingsRepositoryService::PASSWORD_POLICIES,
        ]);
    }

    public function update(UpdateApplicationSettingsRequest $request, SettingsRepositoryService $settings): RedirectResponse
    {
        $settings->setMany($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('application-settings.edit');
    }
}
