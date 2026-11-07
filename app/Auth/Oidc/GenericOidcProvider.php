<?php

declare(strict_types=1);

namespace App\Auth\Oidc;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * Generic OpenID Connect Socialite driver built from the issuer's discovery endpoints.
 * Copied from qiick-io/externa-core until Signaculum SSO exists.
 *
 * PKCE (S256) is always on (#154, OAuth 2.0 Security BCP): Socialite sends `code_challenge`
 * with the authorization request and the session's `code_verifier` with the token exchange.
 */
class GenericOidcProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopeSeparator = ' ';

    /** @var list<string> */
    protected $scopes = ['openid', 'profile', 'email'];

    protected $usesPKCE = true;

    /**
     * @param  array<string, mixed>  $guzzle
     */
    public function __construct(
        Request $request,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
        array $guzzle = [],
        protected string $authorizationEndpoint = '',
        protected string $tokenEndpoint = '',
        protected string $userInfoEndpoint = '',
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->authorizationEndpoint, $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->tokenEndpoint;
    }

    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->userInfoEndpoint, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }
}
