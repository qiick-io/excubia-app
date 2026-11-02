<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateUserAction;
use App\Actions\DeleteUserAction;
use App\Actions\UpdateUserAction;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin pages for dashboard users, behind `users.manage` (UserPolicy).
 */
class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('users/index', [
            'users' => User::query()
                ->with('roles:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => $this->present($user)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return $this->form(null);
    }

    public function store(StoreUserRequest $request, CreateUserAction $createUser): RedirectResponse
    {
        $createUser->execute(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->roles(),
            $request->boolean('is_active', true),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('users.index');
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return $this->form($user->load('roles:id,name'));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $updateUser): RedirectResponse
    {
        $updateUser->execute(
            $user,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->filled('password') ? $request->string('password')->toString() : null,
            $request->roles(),
            $request->boolean('is_active'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('users.index');
    }

    public function destroy(User $user, DeleteUserAction $deleteUser): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $deleteUser->execute($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('users.index');
    }

    /**
     * Sends Fortify's password-reset email. Without mail, `excubia:create-user` stays the way in.
     */
    public function sendPasswordReset(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $status = Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', $status === Password::RESET_LINK_SENT
            ? ['type' => 'success', 'message' => __('Password reset email sent.')]
            : ['type' => 'error', 'message' => __($status)]);

        return back();
    }

    private function form(?User $user): Response
    {
        $actor = request()->user();

        return Inertia::render('users/edit', [
            'user' => $user === null ? null : $this->present($user),
            'roles' => StoreUserRequest::assignableRolesFor($actor instanceof User ? $actor : null),
        ]);
    }

    /**
     * @return array{id: int, name: string, email: string, is_active: bool, is_sso_linked: bool, roles: list<string>, can_update: bool, can_delete: bool}
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'is_sso_linked' => $user->oidc_sub !== null,
            'roles' => array_values($user->getRoleNames()->all()),
            'can_update' => Gate::allows('update', $user),
            'can_delete' => Gate::allows('delete', $user),
        ];
    }
}
