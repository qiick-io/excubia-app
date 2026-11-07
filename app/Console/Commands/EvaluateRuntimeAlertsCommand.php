<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\RuntimeAlertsService;
use Illuminate\Console\Command;

/**
 * Runs the optional runtime alert rules (#107, #114) on every enabled project. Does nothing
 * while no rule is on. Scheduled every fifteen minutes.
 */
class EvaluateRuntimeAlertsCommand extends Command
{
    protected $signature = 'excubia:evaluate-runtime-alerts';

    protected $description = 'Alert on low cache hit ratios, failed mails and notifications, and error-log spikes';

    public function handle(RuntimeAlertsService $rules): int
    {
        if (! $rules->hasRules()) {
            $this->components->info('No runtime alert rule is on.');

            return self::SUCCESS;
        }

        $count = 0;

        Project::query()->where('is_enabled', true)->each(function (Project $project) use ($rules, &$count): void {
            $count += $rules->evaluate($project);
        });

        $this->components->info("Raised {$count} runtime alerts.");

        return self::SUCCESS;
    }
}
