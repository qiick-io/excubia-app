<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateProjectSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * General project settings, as in externa-core: identity (name, description, URL), the
 * reporting links, Checks (stale projects, stale Pennant flags) and Data retention (reports, runtime, exceptions,
 * activity log). The routes require `can-manage-project-settings`.
 */
class ProjectSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/project', [
            'project' => $this->settings->forEditOnly([
                'app_name', 'description', 'url', 'report_issue_url', 'report_bug_url', 'report_error_url',
                'stale_after_hours', 'pennant_stale_after_days', 'reports_kept_per_project', 'runtime_retention_days', 'exceptions_retention_days', 'activity_retention_days',
            ]),
        ]);
    }

    public function update(UpdateProjectSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logProjectSettingsUpdate($request, 'general', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('project.edit');
    }
}
