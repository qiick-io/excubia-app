<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BulkAssignProjectItemsAction;
use App\Http\Requests\BulkAssignFindingsRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Bulk actions on the selected rows of the Findings tab (#276, app-ui optional pattern
 * `inbox-list`): assign them. Dismissing stays per finding, as it takes a reason.
 */
class ProjectFindingBulkController extends Controller
{
    public function __invoke(BulkAssignFindingsRequest $request, #[CurrentUser] User $user, Project $project, BulkAssignProjectItemsAction $assign): RedirectResponse
    {
        $findings = $request->selectedItems();
        $assign->execute($findings, $user, $request->assignee());
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count finding assigned.|:count findings assigned.', $findings->count(), ['count' => $findings->count()])]);

        return back();
    }
}
