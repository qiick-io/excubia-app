<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\CreateFirstAdminAction;
use App\Actions\CreateProjectAction;
use App\Actions\RotateProjectTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFirstAdminRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run wizard at /setup (#116): create the first admin, then the first project, then show
 * its push token with the excubia-core install snippet. The admin step exists only while the
 * database has no user; the project step only for a user who may create projects while no
 * project other than the system one exists. Anything else redirects to the dashboard.
 */
class SetupController extends Controller
{
    /** Session key of the push token shown once on the last step. */
    private const TOKEN_SESSION_KEY = 'setup.pushToken';

    public function show(Request $request): Response|RedirectResponse
    {
        if (! User::query()->exists()) {
            return Inertia::render('auth/setup', [
                'step' => 'admin',
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ]);
        }

        $pushToken = $request->session()->get(self::TOKEN_SESSION_KEY);

        if (is_array($pushToken)) {
            return Inertia::render('auth/setup', ['step' => 'done', 'pushToken' => $pushToken]);
        }

        if ($this->canCreateFirstProject($request->user())) {
            return Inertia::render('auth/setup', ['step' => 'project']);
        }

        return to_route('dashboard');
    }

    public function storeAdmin(StoreFirstAdminRequest $request, CreateFirstAdminAction $createFirstAdmin): RedirectResponse
    {
        $user = $createFirstAdmin->execute(
            $request->string('first_name')->trim()->toString(),
            $request->filled('last_name') ? $request->string('last_name')->trim()->toString() : null,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        abort_if($user === null, 403);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('setup.show');
    }

    public function storeProject(StoreProjectRequest $request, CreateProjectAction $createProject, RotateProjectTokenAction $rotateToken): RedirectResponse
    {
        abort_unless($this->canCreateFirstProject($request->user()), 403);

        $project = $createProject->execute(
            $request->string('name')->trim()->toString(),
            $request->string('slug')->toString(),
            true,
        );

        // Flashed for the next request only: the plain token is never stored.
        $request->session()->flash(self::TOKEN_SESSION_KEY, [
            'project' => $project->name,
            'token' => $rotateToken->execute($project),
            'url' => url('/'),
        ]);

        return to_route('setup.show');
    }

    private function canCreateFirstProject(?User $user): bool
    {
        return $user?->can('create', Project::class) === true
            && ! Project::query()->where('is_system', false)->exists();
    }
}
