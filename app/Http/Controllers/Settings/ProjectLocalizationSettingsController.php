<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProjectLocalizationSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Localization project settings: the default UI language. The routes require `settings.manage`.
 */
class ProjectLocalizationSettingsController extends Controller
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/project/localization', [
            'project' => ['default_language' => $this->settings->defaultLanguage()],
            'availableLocales' => config('i18n.available_locales', ['en' => 'English']),
        ]);
    }

    public function update(UpdateProjectLocalizationSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('project.localization.edit');
    }
}
