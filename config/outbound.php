<?php

declare(strict_types=1);

/*
 * qiick/laravel-outbound configuration.
 *
 * Defaults match the approved design (2026-11-20). Legacy WEBHOOKS_* env names
 * can be mapped by the product when adopting; this package reads OUTBOUND_* only.
 */
return [

    'ssrf' => [

        /*
         * Allow private, ULA, link-local and CGNAT destinations. Metadata,
         * NAT64, 6to4 and Teredo stay blocked either way.
         */
        'allow_private_hosts' => (bool) env('OUTBOUND_ALLOW_PRIVATE_HOSTS', false),

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

    'http' => [

        'timeout_seconds' => (int) env('OUTBOUND_TIMEOUT_SECONDS', 5),

        'connect_timeout_seconds' => (int) env('OUTBOUND_CONNECT_TIMEOUT_SECONDS', 3),

        /*
         * Caps for per-call HttpRequestOptions. Values below 1s or above these
         * caps are rejected before any request is sent.
         */
        'max_timeout_seconds' => (int) env('OUTBOUND_MAX_TIMEOUT_SECONDS', 60),

        'max_connect_timeout_seconds' => (int) env('OUTBOUND_MAX_CONNECT_TIMEOUT_SECONDS', 30),

        /*
         * Wall-clock budget for the whole redirect chain (all hops).
         */
        'total_deadline_seconds' => (int) env('OUTBOUND_TOTAL_DEADLINE_SECONDS', 30),

        'max_response_bytes' => (int) env('OUTBOUND_MAX_RESPONSE_BYTES', 1_048_576),

        /*
         * Transport never auto-follows. When > 0, PinnedHttpClient re-authorises
         * and re-pins each hop up to this cap.
         */
        'max_redirects' => (int) env('OUTBOUND_MAX_REDIRECTS', 0),

    ],

    'webhooks' => [

        'replay_tolerance_seconds' => (int) env('OUTBOUND_WEBHOOK_TOLERANCE_SECONDS', 300),

        'max_payload_bytes' => (int) env('OUTBOUND_WEBHOOK_MAX_PAYLOAD_BYTES', 1_048_576),

        'max_signatures' => (int) env('OUTBOUND_WEBHOOK_MAX_SIGNATURES', 8),

        'max_secrets' => (int) env('OUTBOUND_WEBHOOK_MAX_SECRETS', 4),

        /*
         * RetrySchedule preset: "standard" (Standard Webhooks example) or
         * "short" ([10, 30, 60], migration window only).
         */
        'retry_preset' => env('OUTBOUND_WEBHOOK_RETRY_PRESET', 'standard'),

        'retry_jitter_seconds' => (int) env('OUTBOUND_WEBHOOK_RETRY_JITTER', 30),

        /*
         * Allow WebhookSecret::parseAllowingLegacyRaw to accept non-whsec_
         * secrets. Off by default; products turn it on only during migration.
         */
        'allow_raw_legacy_secret' => (bool) env('OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET', false),

    ],

];
