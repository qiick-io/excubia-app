<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Maintenance from the environment
    |--------------------------------------------------------------------------
    |
    | While set, the app is in maintenance and `{APP_URL}/{secret}` is the only way in (the
    | bypass cookie of `artisan down --secret`). Unlike `artisan down` it survives a deploy and a
    | new database; remove it and redeploy to reopen. A long random value, never a guessable word.
    |
    */

    'maintenance_secret' => env('EXCUBIA_MAINTENANCE_SECRET'),

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

    // Hours of per-minute runtime rows kept (#212, at least 24): excubia:downsample-runtime folds
    // older minutes into one row per series and hour every hour, which the 24 h, 7 d and 14 d
    // ranges read with the same totals. The hourly rows are kept for the runtime retention.
    'runtime_minute_hours' => (int) env('EXCUBIA_RUNTIME_MINUTE_HOURS', 48),

    'exceptions_retention_days' => (int) env('EXCUBIA_EXCEPTIONS_RETENTION_DAYS', 90),

    // Days of exception occurrences kept for the charts and "In range" counts (#214, at least 14,
    // the longest range; never longer than the exceptions retention). Older than the per-minute
    // window they are hourly rows; the groups keep their totals for the exceptions retention.
    'exception_occurrence_days' => (int) env('EXCUBIA_EXCEPTION_OCCURRENCE_DAYS', 14),

    // Days of checks with always the same value after which a Laravel Pennant feature counts as a
    // stale flag on the Runtime tab (#189); judged over the runtime retention when that is shorter.
    'pennant_stale_after_days' => (int) env('EXCUBIA_PENNANT_STALE_AFTER_DAYS', 30),

    // The system project also records this install's own runtime and exceptions (#84). The data
    // stays in this database, masked by excubia-core like any project's.
    'self_monitoring' => (bool) env('EXCUBIA_SELF_MONITORING', true),

    // The runtime types the system project records (#219), comma-separated `excubia.json` keys
    // (`requests,queueWait,server,health`) or `all`. Empty: every type the installed excubia-core
    // and this app support, with `cache` sampled (SelfMonitoringService). Kept out of the shipped
    // `resources/excubia/self-monitoring.json`, so an upgrade never resets it.
    'self_monitoring_types' => env('EXCUBIA_SELF_MONITORING_TYPES'),

    // Reverse proxies whose X-Forwarded-* headers are trusted (#116), comma-separated IPs or
    // CIDR ranges, or `*` for any. Empty trusts none. Set it when a TLS proxy sits in front of
    // the app (Docker), so URLs and push snippets use https and the client IP is the real one.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Strict-Transport-Security max-age in seconds (#153, #478). By default one year in production
    // when APP_URL is https, else 0 (none); a proxy may send its own. 0 turns it off.
    'hsts_max_age' => (int) env(
        'EXCUBIA_HSTS_MAX_AGE',
        env('APP_ENV', 'production') === 'production' && str_starts_with((string) env('APP_URL'), 'https://') ? 31536000 : 0,
    ),

    /*
    |--------------------------------------------------------------------------
    | Uploaded files
    |--------------------------------------------------------------------------
    |
    | The disk (config/filesystems.php) of project logos and branding files
    | (#312). Empty keeps logos on `public` and branding on `local`. Set a
    | bucket disk (`s3`) where the filesystem is rebuilt on every deploy,
    | such as Laravel Cloud. The app streams the files, so it can be private.
    |
    */

    'files_disk' => env('EXCUBIA_FILES_DISK'),

    /*
    |--------------------------------------------------------------------------
    | Assistant
    |--------------------------------------------------------------------------
    |
    | The AI assistant is off by default: no page, no sidebar module and no
    | outbound call. Set the provider in config/ai.php (Ollama by default).
    |
    | Its write tools (#320: exception status, assignment, finding dismissal,
    | alert mutes and maintenance windows, refresh report) are on by default,
    | run only after the user approves the call, and are logged. Set
    | EXCUBIA_AI_WRITE_ENABLED=false for a read-only assistant and MCP server.
    |
    */

    'ai_enabled' => (bool) env('EXCUBIA_AI_ENABLED', false),

    'ai_write_enabled' => (bool) env('EXCUBIA_AI_WRITE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | The MCP server at POST /mcp (#234) is off by default: the endpoint
    | answers 404. Clients authenticate with a personal API key with the
    | mcp:read scope. It needs no AI provider. See docs/mcp.md.
    |
    | Once it is on, its write tools (#320) are on too, for keys that also
    | have the mcp:write scope. EXCUBIA_MCP_WRITE_ENABLED=false keeps MCP
    | read-only while the assistant may still write.
    |
    */

    'mcp_enabled' => (bool) env('EXCUBIA_MCP_ENABLED', false),

    'mcp_write_enabled' => (bool) env('EXCUBIA_MCP_WRITE_ENABLED', true),

];
