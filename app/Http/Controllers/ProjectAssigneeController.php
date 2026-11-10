<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssignProjectItemAction;
use App\Http\Requests\UpdateAssigneeRequest;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Assigns or unassigns an exception group or a finding (#254), from the drawers of the
 * Exceptions and Findings tabs. The typed route models are what binds them; the request checks
 * access and the assignee.
 */
class ProjectAssigneeController extends Controller
{
    public function exception(UpdateAssigneeRequest $request, Project $project, ExceptionGroup $exceptionGroup, AssignProjectItemAction $assign): RedirectResponse
    {
        return $this->assign($request, $exceptionGroup, $assign);
    }

    public function finding(UpdateAssigneeRequest $request, Project $project, Finding $finding, AssignProjectItemAction $assign): RedirectResponse
    {
        return $this->assign($request, $finding, $assign);
    }

    private function assign(UpdateAssigneeRequest $request, ExceptionGroup|Finding $item, AssignProjectItemAction $assign): RedirectResponse
    {
        $actor = $request->user();
        $assignee = $request->assignee();

        if ($actor instanceof User) {
            $assign->execute($item, $actor, $assignee);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $assignee === null
            ? __('Unassigned.')
            : __('Assigned to :name.', ['name' => $assignee->name])]);

        return back();
    }
}
