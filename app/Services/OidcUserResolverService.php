<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as SocialiteTwoUser;

/**
 * Resolves the local user of an OIDC identity, following the qiick auth contract:
 * `sub`, `email` and `email_verified = true` are required; match by `sub`, then link by email
 * (refused when the user is linked to another `sub`); otherwise refuse unless JIT provisioning
 * creates a `reader`. Inactive users are always refused. Roles stay per app.
 * Copied from qiick-io/externa-core until Signaculum SSO exists.
 */
class OidcUserResolverService
{
    public function __construct(private SettingsRepositoryService $settings) {}

    /**
     * @throws ValidationException under `email` when the login is refused
     */
    public function resolve(SocialiteUser $socialiteUser): User
    {
        $sub = trim((string) $socialiteUser->getId());
        $email = Str::lower(trim((string) $socialiteUser->getEmail()));
        $raw = $socialiteUser instanceof SocialiteTwoUser ? $socialiteUser->getRaw() : [];

        if ($sub === '') {
            $this->refuse(__('SSO login failed: missing subject claim.'));
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->refuse(__('SSO login failed: missing email claim.'));
        }

        if (! $this->isEmailVerified($raw)) {
            $this->refuse(__('SSO login requires a verified email from the identity provider.'));
        }

        $bySub = User::query()->where('oidc_sub', $sub)->first();

        if ($bySub !== null) {
            return $this->assertActive($bySub);
        }

        $byEmail = User::query()->where('email', $email)->first();

        if ($byEmail !== null) {
            $user = $this->assertActive($byEmail);

            if ($user->oidc_sub !== null && $user->oidc_sub !== '') {
                $this->refuse(__('This account is already linked to a different SSO identity.'));
            }

            $user->forceFill(['oidc_sub' => $sub])->save();

            return $user;
        }

        if (! $this->settings->isOidcJitProvisioningEnabled()) {
            $this->refuse(__('No local account matches this SSO email. Ask an admin to create your user first.'));
        }

        [$firstName, $lastName] = $this->nameParts($raw, (string) $socialiteUser->getName(), $email);

        $user = (new User)->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => null,
            'oidc_sub' => $sub,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->save();
        $user->assignRole(RoleEnum::Reader->value);

        return $user;
    }

    /**
     * A missing flag means refuse: some IdPs omit it, and an unverified email must never link.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private function isEmailVerified(array $raw): bool
    {
        $flag = $raw['email_verified'] ?? null;

        if (is_bool($flag)) {
            return $flag;
        }

        return is_string($flag) && filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function assertActive(User $user): User
    {
        if (! $user->is_active) {
            $this->refuse(__('This account is inactive.'));
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['email' => $message]);
    }

    /**
     * First and last name from the OIDC `given_name` / `family_name` claims, else from `name`
     * (first word, then the rest), else the email's local part.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: string|null}
     */
    private function nameParts(array $raw, string $name, string $email): array
    {
        $given = trim((string) ($raw['given_name'] ?? ''));

        if ($given !== '') {
            $family = trim((string) ($raw['family_name'] ?? ''));

            return [$given, $family !== '' ? $family : null];
        }

        $name = trim($name);

        if ($name === '') {
            return [Str::before($email, '@'), null];
        }

        $last = trim(Str::after($name, ' '));

        return [Str::before($name, ' '), str_contains($name, ' ') && $last !== '' ? $last : null];
    }
}
