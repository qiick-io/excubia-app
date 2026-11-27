<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\PinProjectAction;
use App\Actions\UnpinProjectAction;
use App\Http\Requests\PinProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * Pins and unpins a project on the user's sidebar (#273), from the projects list and the
 * project header.
 */
class ProjectPinController extends Controller
{
    public function store(PinProjectRequest $request, #[CurrentUser] User $user, Project $project, PinProjectAction $pin): RedirectResponse
    {
        $pin->execute($user, $project);

        return back();
    }

    public function destroy(PinProjectRequest $request, #[CurrentUser] User $user, Project $project, UnpinProjectAction $unpin): RedirectResponse
    {
        $unpin->execute($user, $project);

        return back();
    }
}
