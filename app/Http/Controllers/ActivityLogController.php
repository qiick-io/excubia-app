<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityEventEnum;
use App\Http\Requests\ActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Activity log browser with user, action, log and date filters, behind `can-show-activity-logs`
 * (ActivityPolicy). Copied from qiick-io/externa-core.
 */
class ActivityLogController extends Controller
{
    public function index(ActivityLogRequest $request, ActivityLogService $activityLogs): Response
    {
        Gate::authorize('viewAny', Activity::class);

        $filters = $request->activityFilters();

        return Inertia::render('admin/activity-logs/index', [
            'activityLogs' => ActivityLogResource::collection($activityLogs->filteredQuery($filters, $request->user())
                ->paginate($request->perPage())
                ->withQueryString()),
            // Labels for the selected users of the user filter; the picker searches the rest.
            'users' => User::query()
                ->whereIn('id', $filters['user_ids'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]),
            'events' => ActivityEventEnum::values(),
            'logNames' => ActivityLogRequest::LOG_NAMES,
            'filters' => $filters,
        ]);
    }
}
