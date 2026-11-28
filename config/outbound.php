<?php

declare(strict_types=1);

/*
 * Excubia's overrides of the qiick/laravel-outbound defaults (vendor/qiick/laravel-outbound/config/outbound.php,
 * which documents every key). Keys left out here keep the package value: since 0.2.0-beta.4 the
 * package fills every nested key this file leaves out, so only the overrides live here.
 */
return [

    'ssrf' => [

        /*
         * Allow private, ULA, link-local and CGNAT destinations. Metadata,
         * NAT64, 6to4 and Teredo stay blocked either way. The ceiling (outbound
         * 0.2.0-beta.2): a call's UrlGuardOptions or an endpoint's
         * `allow_private_hosts` can only narrow it, never allow what this refuses.
         * Fail-closed like https_only: only an explicit true (true/1/yes/on) opens
         * private hosts; an off value or a typo keeps them refused.
         */
        'allow_private_hosts' => filter_var(env('OUTBOUND_ALLOW_PRIVATE_HOSTS', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,

        /*
         * Restrict server-side fetches to https. Browser-target checks may use
         * a different UrlGuardOptions per call. Fail-closed: only filter_var with
         * FILTER_VALIDATE_BOOLEAN + FILTER_NULL_ON_FAILURE === true enables it
         * (0/no/off/empty/null stay false). Production refuses to boot unless
         * this is true (see AppServiceProvider).
         */
        'https_only' => filter_var(
            env('OUTBOUND_HTTPS_ONLY', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) === true,

    ],

    'webhooks' => [

        /*
         * Off: routes/console.php schedules `outbound:prune-deliveries` itself, so the alert
         * history reconciliation runs after every prune.
         */
        'schedule' => false,

    ],

];
