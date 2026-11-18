<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Support\Str;
use Qiick\Oidc\Contracts\UserProvisioningContract;
use Qiick\Oidc\Data\AuthenticatedIdentityData;

/**
 * Creates the local user of a just-in-time SSO login (Settings → Application → JIT): an active
 * `reader` without a password, so the account can only sign in through SSO until an admin or a
 * password reset gives it one. Roles stay local: the IdP never grants more than `reader`.
 *
 * Names follow the qiick rule (AuthenticatedIdentityData::personName()): `given_name` and
 * `family_name`, else `name` with its last word as the last name, else the email's local part.
 */
class OidcUserProvisioningService implements UserProvisioningContract
{
    /**
     * @param  string  $email  verified and lower-cased by the package
     */
    public function provision(AuthenticatedIdentityData $identity, string $email): User
    {
        $personName = $identity->personName();

        $user = (new User)->forceFill([
            'first_name' => $personName?->givenName ?: Str::before($email, '@'),
            'last_name' => $personName?->familyName ?: null,
            'email' => $email,
            'password' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->save();
        $user->assignRole(RoleEnum::Reader->value);

        return $user;
    }
}
