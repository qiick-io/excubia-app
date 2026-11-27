<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A failed password reset always says the link is invalid (#472): "we can't find a user with that
 * email address" next to "this token is invalid" told anyone which addresses have an account.
 */
final class PasswordResetFailedResponse implements FailedPasswordResetResponse
{
    /**
     * @throws ValidationException for JSON requests
     */
    public function toResponse($request): Response
    {
        $message = (string) trans(PasswordBroker::INVALID_TOKEN);

        if ($request->wantsJson()) {
            throw ValidationException::withMessages(['email' => [$message]]);
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }
}
