<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateAppearanceSidebarSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Main sidebar module order and visibility. The routes require `can-manage-project-settings`.
 */
class AppearanceSidebarSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/appearance/sidebar', [
            'project' => ['sidebar_modules' => $this->settings->sidebarModules()],
        ]);
    }

    public function update(UpdateAppearanceSidebarSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logProjectSettingsUpdate($request, 'sidebar', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('appearance.sidebar.edit');
    }
}
