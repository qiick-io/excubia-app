<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Report history and stale projects
    |--------------------------------------------------------------------------
    |
    | Defaults for the "Checks" application settings. A value saved in the
    | settings page wins over these.
    |
    */

    'reports_kept_per_project' => (int) env('EXCUBIA_REPORTS_KEPT_PER_PROJECT', 30),

    'stale_after_hours' => (int) env('EXCUBIA_STALE_AFTER_HOURS', 36),

    /*
    |--------------------------------------------------------------------------
    | Push endpoints
    |--------------------------------------------------------------------------
    |
    | Limits for the bodies projects push with excubia push: the largest body
    | accepted, and the pushes allowed per minute for one project token.
    |
    */

    'push_max_kilobytes' => (int) env('EXCUBIA_PUSH_MAX_KILOBYTES', 1024),

    'push_requests_per_minute' => (int) env('EXCUBIA_PUSH_REQUESTS_PER_MINUTE', 30),

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | The same event, project and subject alerts at most once in this many
    | minutes (0 turns the throttle off); a worse severity still goes out.
    | `push.rejected` is raised after this many pushes rejected for their
    | token within an hour, per project (disabled) or per client (unknown).
    |
    */

    'alert_throttle_minutes' => (int) env('EXCUBIA_ALERT_THROTTLE_MINUTES', 60),

    'push_rejected_alert_after' => (int) env('EXCUBIA_PUSH_REJECTED_ALERT_AFTER', 10),

    // Days before the end of security support of PHP, Laravel or Node at which `eol.approaching`
    // alerts (#157), comma-separated; each one alerts once per runtime cycle.
    'eol_alert_days' => array_map('intval', explode(',', (string) env('EXCUBIA_EOL_ALERT_DAYS', '90,30,7'))),

    // Days of runtime aggregates (#48) and of exception groups not seen since (#49) kept;
    // older ones are pruned daily by excubia:prune-telemetry.
    'runtime_retention_days' => (int) env('EXCUBIA_RUNTIME_RETENTION_DAYS', 14),

    'exceptions_retention_days' => (int) env('EXCUBIA_EXCEPTIONS_RETENTION_DAYS', 90),

    // The system project also records this install's own runtime and exceptions (#84). The data
    // stays in this database, masked by excubia-core like any project's.
    'self_monitoring' => (bool) env('EXCUBIA_SELF_MONITORING', true),

    // Reverse proxies whose X-Forwarded-* headers are trusted (#116), comma-separated IPs or
    // CIDR ranges, or `*` for any. Empty trusts none. Set it when a TLS proxy sits in front of
    // the app (Docker), so URLs and push snippets use https and the client IP is the real one.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Strict-Transport-Security max-age in seconds on https requests (#153). 0 (the default)
    // sends none: HSTS is usually set by the TLS proxy. 31536000 is one year.
    'hsts_max_age' => (int) env('EXCUBIA_HSTS_MAX_AGE', 0),

    /*
    |--------------------------------------------------------------------------
    | Assistant
    |--------------------------------------------------------------------------
    |
    | The AI assistant is off by default: no page, no sidebar module and no
    | outbound call. Set the provider in config/ai.php (Ollama by default).
    |
    */

    'ai_enabled' => (bool) env('EXCUBIA_AI_ENABLED', false),

];
