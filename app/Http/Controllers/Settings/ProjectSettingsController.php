<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProjectSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * General project settings: application name, bug report link and Checks (report history,
 * stale projects). The routes require `settings.manage`.
 */
class ProjectSettingsController extends Controller
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/project', [
            'project' => $this->settings->forEditOnly(['app_name', 'report_bug_url', 'reports_kept_per_project', 'stale_after_hours']),
        ]);
    }

    public function update(UpdateProjectSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('project.edit');
    }
}
