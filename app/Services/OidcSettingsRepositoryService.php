<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Qiick\Oidc\Contracts\ProviderConfigurationRepositoryContract;
use Qiick\Oidc\Data\ProviderConfigurationData;
use Qiick\Oidc\Exceptions\OidcNotConfiguredException;

/**
 * The OIDC provider of qiick/laravel-oidc, read from Settings → Application (the `settings`
 * table), so SSO stays configured from the UI and never from `.env`.
 *
 * The settings table is the only source: there is no fallback to `config('oidc.*')` or the
 * `OIDC_*` env vars, so an install whose settings say SSO is off can never have it switched on
 * by an env var. isEnabled() is the guard of the package's three routes (redirect, callback,
 * back-channel logout): they answer 404 while it is false. It needs SSO switched on with the
 * issuer, client id and a client secret that still decrypts (an APP_KEY change turns SSO off
 * instead of sending an empty secret).
 *
 * The issuer is used exactly as saved (trimmed): the package compares it with the discovery
 * document's `issuer` character by character, and the imported identities carry it too.
 */
class OidcSettingsRepositoryService implements ProviderConfigurationRepositoryContract
{
    public function __construct(private SettingsRepositoryService $settings) {}

    public function isEnabled(): bool
    {
        return $this->settings->isOidcLoginAvailable();
    }

    /**
     * @throws OidcNotConfiguredException when SSO is off or incomplete
     */
    public function configuration(): ProviderConfigurationData
    {
        if (! $this->isEnabled()) {
            throw new OidcNotConfiguredException('SSO is off or incomplete in Settings → Application.');
        }

        return new ProviderConfigurationData(
            issuer: (string) $this->settings->oidcIssuer(),
            clientId: (string) $this->settings->oidcClientId(),
            clientSecret: $this->settings->oidcClientSecret(),
            redirectUri: route('oidc.callback'),
            scopes: ['openid', 'profile', 'email'],
            postLogoutRedirectUri: route('login'),
            maxAge: Config::integer('session.absolute_lifetime') * 60,
        );
    }
}
