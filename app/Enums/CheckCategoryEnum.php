<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The group of an excubia-core check id (#109), in the order the project page shows them:
 * infrastructure (services the app needs to run), configuration (posture of the deployment),
 * dependencies (packages and runtimes). A check id this app does not know yet is `Other`, so a
 * newer excubia-core still renders, under its own name.
 */
enum CheckCategoryEnum: string
{
    case Infrastructure = 'infrastructure';
    case Configuration = 'configuration';
    case Dependencies = 'dependencies';
    case Other = 'other';

    /**
     * Infrastructure checks whose failure alerts at once (`infrastructure.failed`), even on a
     * project's first report: the app cannot serve requests without them.
     */
    public const IMMEDIATE_ALERT_CHECK_IDS = ['laravel-database', 'laravel-storage', 'laravel-disk-space'];

    /**
     * The 37 check ids of excubia-core `docs/CONTRACTS.md` (1.0.0-beta.3) by group.
     *
     * @var array<string, list<string>>
     */
    private const CHECK_IDS = [
        'infrastructure' => [
            'laravel-database',
            'laravel-cache',
            'laravel-storage',
            'laravel-disk-space',
            'laravel-queue-backlog',
            'laravel-queue-workers',
            'laravel-failed-jobs',
            'laravel-scheduler-heartbeat',
            'laravel-pending-migrations',
            'laravel-backups',
            'laravel-database-connections',
            'laravel-database-size',
            'laravel-redis-memory',
            'php-platform-requirements',
        ],
        'configuration' => [
            'laravel-debug-mode',
            'laravel-production-caches',
            'laravel-maintenance-mode',
            'laravel-app-key',
            'laravel-session-security',
            'laravel-cors-wildcard',
            'laravel-production-drivers',
            'laravel-log-level',
            'laravel-dev-packages',
            'env-example-sync',
            'php-opcache',
            'php-production-ini',
        ],
        'dependencies' => [
            'php-eol',
            'laravel-eol',
            'node-eol',
            'composer-advisories',
            'npm-advisories',
            'composer-abandoned',
            'composer-stale',
            'laravel-next-blocker',
            'dependency-licenses',
            'composer-outdated',
            'npm-outdated',
        ],
    ];

    public static function forCheck(string $checkId): self
    {
        foreach (self::CHECK_IDS as $category => $checkIds) {
            if (in_array($checkId, $checkIds, true)) {
                return self::from($category);
            }
        }

        return self::Other;
    }

    /**
     * @return list<string> every check id this app knows
     */
    public static function knownCheckIds(): array
    {
        return array_merge(...array_values(self::CHECK_IDS));
    }

    /**
     * Lower comes first on the page: infrastructure is 0, other is 3.
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
