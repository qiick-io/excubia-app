<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\FileBasedMaintenanceMode;

/**
 * Laravel's maintenance mode, also switched on from the environment: while
 * EXCUBIA_MAINTENANCE_SECRET is set the app is down, and only `{APP_URL}/{secret}` (the bypass
 * cookie of `artisan down --secret`) lets someone in. It survives what the file flag does not: a
 * Laravel Cloud deploy resets the replicas' filesystem, and the group A cutover replaces the
 * database a cache-based flag would live in. Queue workers pause and the scheduler skips tasks,
 * as for any maintenance.
 *
 * Without the variable it is the default file driver, so `artisan down` and `up` work as usual.
 */
class EnvironmentMaintenanceModeService implements MaintenanceMode
{
    public function __construct(private readonly FileBasedMaintenanceMode $fileMaintenance) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function activate(array $payload): void
    {
        $this->fileMaintenance->activate($payload);
    }

    /**
     * `artisan up` lifts only the file flag: the environment one ends when the variable is removed.
     */
    public function deactivate(): void
    {
        $this->fileMaintenance->deactivate();
    }

    public function active(): bool
    {
        return $this->secret() !== null || $this->fileMaintenance->active();
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $secret = $this->secret();

        return $secret === null ? $this->fileMaintenance->data() : ['secret' => $secret, 'status' => 503];
    }

    private function secret(): ?string
    {
        $secret = config('excubia.maintenance_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
