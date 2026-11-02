<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OidcSocialiteFactoryService;
use App\Services\OidcUserResolverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * OIDC authorize redirect and callback into the Fortify web session (`auth/oidc/redirect|callback`).
 * Copied from qiick-io/externa-core until Signaculum SSO exists.
 */
class OidcController extends Controller
{
    public function redirect(OidcSocialiteFactoryService $factory): RedirectResponse
    {
        abort_unless($factory->enabled(), 404);

        return $factory->driver()->redirect();
    }

    public function callback(
        Request $request,
        OidcSocialiteFactoryService $factory,
        OidcUserResolverService $resolver,
        LoginResponse $loginResponse,
    ): Response {
        abort_unless($factory->enabled(), 404);

        try {
            $user = $resolver->resolve($factory->driver()->user());
        } catch (ValidationException $exception) {
            // The IdP is the referer: back() would bounce between the IdP and the callback.
            throw $exception->redirectTo(route('login'));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'email' => __('SSO login failed. Try again or use email and password.'),
            ])->redirectTo(route('login'));
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return $loginResponse->toResponse($request);
    }
}
