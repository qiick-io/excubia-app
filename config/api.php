<?php

declare(strict_types=1);

/**
 * API key baseline settings (#351): rotation grace and the default per-key rate limit fallback.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Key rotation grace window
    |--------------------------------------------------------------------------
    |
    | Hours the previous secret keeps working after a rotation, so an integration
    | can switch without downtime. The previous hash is cleared on revoke.
    |
    */

    'key_rotation_grace_hours' => (int) env('API_KEY_ROTATION_GRACE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Expiry notice window
    |--------------------------------------------------------------------------
    |
    | Days before expires_at when the daily notify command warns the owner (and
    | the issuer when different). The command selects the [N, N+1) day window
    | so each key is notified once without extra state. Env name matches
    | externa-core / the fleet map (app-ui#178): API_KEY_EXPIRY_NOTICE_DAYS.
    |
    */

    'key_expiry_notice_days' => max(1, (int) env('API_KEY_EXPIRY_NOTICE_DAYS', 7)),

];
