<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteFailedJobAction;
use App\Actions\RetryFailedJobAction;
use App\Services\JobMonitorQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lightweight queue monitor (`/settings/jobs`): pending jobs and failed jobs with retry and
 * delete, behind `can-show-jobs` / `can-manage-jobs` (JobPolicy). Copied from qiick-io/externa-core.
 */
class JobMonitorController extends Controller
{
    public function index(JobMonitorQueryService $jobs): Response
    {
        Gate::authorize('viewAny', JobMonitorQueryService::class);

        return Inertia::render('admin/jobs/index', $jobs->snapshot());
    }

    public function retry(string $uuid, RetryFailedJobAction $retryFailedJob): RedirectResponse
    {
        Gate::authorize('retry', JobMonitorQueryService::class);

        $isRetried = $retryFailedJob->execute($uuid);

        Inertia::flash('toast', $isRetried
            ? ['type' => 'success', 'message' => __('Job queued for retry.')]
            : ['type' => 'error', 'message' => __('Failed job not found.')]);

        return to_route('jobs.index');
    }

    public function destroy(string $uuid, DeleteFailedJobAction $deleteFailedJob): RedirectResponse
    {
        Gate::authorize('delete', JobMonitorQueryService::class);

        $isDeleted = $deleteFailedJob->execute($uuid);

        Inertia::flash('toast', $isDeleted
            ? ['type' => 'success', 'message' => __('Failed job deleted.')]
            : ['type' => 'error', 'message' => __('Failed job not found.')]);

        return to_route('jobs.index');
    }
}
