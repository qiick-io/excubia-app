<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RevokeProjectTokenAction;
use App\Actions\RotateProjectTokenAction;
use App\Models\Project;
use App\Traits\FlashesPushTokenTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A project's push token (ProjectPolicy::manageToken): issue a new one, which stops the old one
 * at once and is shown once, or revoke it, which rejects pushes until a new one is issued.
 */
class ProjectTokenController extends Controller
{
    use FlashesPushTokenTrait;

    public function store(Project $project, RotateProjectTokenAction $rotateToken): RedirectResponse
    {
        Gate::authorize('manageToken', $project);

        $this->flashToken($project, $rotateToken->execute($project));

        return to_route('projects.index');
    }

    public function destroy(Project $project, RevokeProjectTokenAction $revokeToken): RedirectResponse
    {
        Gate::authorize('manageToken', $project);

        $revokeToken->execute($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Push token revoked.')]);

        return to_route('projects.index');
    }
}
