<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BulkAssignProjectItemsAction;
use App\Actions\BulkUpdateExceptionStatusAction;
use App\Enums\ExceptionStatusEnum;
use App\Http\Requests\BulkAssignExceptionGroupsRequest;
use App\Http\Requests\BulkUpdateExceptionStatusRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Bulk actions on the selected rows of the Exceptions tab (#276, app-ui optional pattern
 * `inbox-list`): change their status, or assign them.
 */
class ProjectExceptionBulkController extends Controller
{
    public function status(BulkUpdateExceptionStatusRequest $request, #[CurrentUser] User $user, Project $project, BulkUpdateExceptionStatusAction $updateStatus): RedirectResponse
    {
        $status = $request->enum('status', ExceptionStatusEnum::class) ?? ExceptionStatusEnum::Open;
        $changed = $updateStatus->execute($request->exceptionGroups(), $status, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count exception updated.|:count exceptions updated.', $changed, ['count' => $changed])]);

        return back();
    }

    public function assignee(BulkAssignExceptionGroupsRequest $request, #[CurrentUser] User $user, Project $project, BulkAssignProjectItemsAction $assign): RedirectResponse
    {
        $groups = $request->exceptionGroups();
        $assign->execute($groups, $user, $request->assignee());
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count exception assigned.|:count exceptions assigned.', $groups->count(), ['count' => $groups->count()])]);

        return back();
    }
}
