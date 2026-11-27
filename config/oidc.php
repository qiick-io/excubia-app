<?php

declare(strict_types=1);

/*
 * Excubia's overrides of the qiick/laravel-oidc defaults (vendor/qiick/laravel-oidc/config/oidc.php).
 * Only these top-level keys are replaced; every other key keeps the package default.
 *
 * The provider (issuer, client id and secret), JIT, auto-link, SSO-only mode and the button label
 * are NOT read from here or from OIDC_* env vars: they come from Settings → Application
 * (AppServiceProvider::registerOidcBindings()). Only the Docker and lab variables of `http` are
 * env vars.
 */
return [

    'home' => '/dashboard',

    /*
     * `oidc.routes.enabled` stays true even while SSO is off: the three routes then answer 404
     * through OidcSettingsRepositoryService::isEnabled(). Each callback makes token and userinfo
     * calls to the IdP, so redirect and callback keep the throttle they had before (#154).
     */
    'routes' => [

        'enabled' => true,

        'prefix' => 'auth/oidc',

        'middleware' => ['web', 'guest', 'throttle:10,1'],

    ],

    /*
     * The package defaults, listed so .env.example documents their env vars. `discovery_base` and
     * `server_origin` serve Docker setups where the app reaches the IdP under another host than
     * the browser; `allow_private_hosts` lets the SSRF guard accept a private-network IdP (only an explicit true:
     * an off value or a typo keeps private hosts refused).
     */
    'http' => [

        'timeout' => 10,

        'discovery_base' => env('OIDC_DISCOVERY_BASE'),

        'server_origin' => env('OIDC_SERVER_ORIGIN'),

        'allow_private_hosts' => filter_var(env('OIDC_ALLOW_PRIVATE_HOSTS', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,

    ],

];
