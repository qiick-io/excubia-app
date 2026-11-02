<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateUserAction;
use App\Actions\DeleteUserAction;
use App\Actions\UpdateUserAction;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin pages for dashboard users, behind `users.manage` (UserPolicy). Create and edit happen
 * in a drawer on the list page.
 */
class UserController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'email', 'is_active', 'created_at', 'updated_at'];

    /**
     * Lists users with search, sort and pagination. A JSON request feeds the user picker of the
     * group drawer (UserPolicy::pick).
     */
    public function index(ListQueryRequest $request): Response|JsonResponse
    {
        $actor = $request->user();
        $wantsJson = $request->expectsJson();

        Gate::authorize($wantsJson ? 'pick' : 'viewAny', User::class);

        $sort = $request->sortColumn(self::SORTABLE_COLUMNS, 'created_at');
        $direction = $request->sortDirection('desc');

        $query = $request->applySearch(User::query(), ['name', 'email']);

        if (! $wantsJson) {
            $query->with(['roles:id,name', 'groups:id,name']);
        }

        $users = $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($wantsJson) {
            return UserResource::collection($users)->response($request);
        }

        return Inertia::render('admin/users/index', [
            'users' => UserResource::collection($users),
            'filters' => $request->filters($sort, $direction),
            'roles' => StoreUserRequest::assignableRoleOptionsFor($actor instanceof User ? $actor : null),
        ]);
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
}
