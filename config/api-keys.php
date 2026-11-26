<?php

declare(strict_types=1);

use App\Notifications\ApiKeyExpiringNotification;

/**
 * Excubia's overrides of the qiick/laravel-api-keys defaults (vendor/qiick/laravel-api-keys/config/api-keys.php,
 * which documents every key). Keys left out here keep the package value.
 */
return [

    // Three-letter product code in every token: `exc_{live|test}_…` (fleet scanner pattern).
    'product_prefix' => 'exc',

    // Hours the previous secret keeps working after a rotation (0 ends it at once, at most 168).
    'rotation_grace_hours' => (int) env('API_KEYS_ROTATION_GRACE_HOURS', 24),

    // Requests per minute for a key without its own `rate_limit_per_minute`.
    'default_rate_limit_per_minute' => (int) env('API_KEYS_DEFAULT_RATE_LIMIT_PER_MINUTE', 60),

    // Failed guesses per minute from one client IP (/32, IPv6 /64) before every attempt answers 429.
    'failed_attempts_per_minute' => env('API_KEYS_FAILED_ATTEMPTS_PER_MINUTE', 20),

    'expiry' => [
        // Days before `expires_at` when the daily `api-keys:notify-expiring` warns, once per key.
        'notice_days' => (int) env('API_KEYS_EXPIRY_NOTICE_DAYS', 7),
        // Excubia's translated mail and bell notification.
        'notification' => ApiKeyExpiringNotification::class,
    ],

];
