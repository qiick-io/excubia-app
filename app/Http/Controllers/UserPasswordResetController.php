<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;

/**
 * Sends Fortify's password-reset email to a user from the admin users list (UserPolicy::update).
 * Without mail, `excubia:create-user` stays the way in.
 */
class UserPasswordResetController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $status = Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', $status === Password::RESET_LINK_SENT
            ? ['type' => 'success', 'message' => __('Password reset email sent.')]
            : ['type' => 'error', 'message' => __($status)]);

        return back();
    }
}
