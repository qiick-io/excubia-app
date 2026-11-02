<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAppearanceSidebarSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Main sidebar module order and visibility. The routes require `settings.manage`.
 */
class AppearanceSidebarSettingsController extends Controller
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/appearance/sidebar', [
            'project' => ['sidebar_modules' => $this->settings->sidebarModules()],
        ]);
    }

    public function update(UpdateAppearanceSidebarSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('appearance.sidebar.edit');
    }
}
