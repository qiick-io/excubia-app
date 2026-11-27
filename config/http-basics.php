<?php

declare(strict_types=1);

/*
 * Excubia's overrides of the qiick/laravel-http-basics defaults
 * (vendor/qiick/laravel-http-basics/config/http-basics.php documents every key). Keys left out
 * keep the package value: the package fills every nested key this file leaves out. The supported
 * locales and the default locale come from `config/i18n.php` and Settings → Localization
 * (AppServiceProvider binds them), not from here.
 */
return [

    'security' => [

        'csp' => [

            /*
             * Off until the inline appearance script and the Vite dev server get nonces: the
             * branding route sends its own CSP, which the package keeps.
             */
            'mode' => env('HTTP_BASICS_CSP_MODE', 'off'),

        ],

        'hsts' => [

            /*
             * Strict-Transport-Security max-age in seconds (#153, #478), on https requests only:
             * behind a TLS proxy set TRUSTED_PROXIES (Laravel Cloud is trusted automatically).
             * By default one year in production when APP_URL is https, else 0 (none); a proxy may
             * send its own. 0 turns it off (EXCUBIA_HSTS_MAX_AGE before 1.0).
             */
            'max_age' => (int) env(
                'HTTP_BASICS_HSTS_MAX_AGE',
                env('APP_ENV', 'production') === 'production' && str_starts_with((string) env('APP_URL'), 'https://') ? 31536000 : 0,
            ),

        ],

    ],

];
