<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\AiAlertsService;
use App\Services\RuntimeAlertsService;
use Illuminate\Console\Command;

/**
 * Runs the optional runtime alert rules (#107, #114, billing webhooks #178, failed logins #180) and the AI usage rules (#129) on every
 * enabled project. Does nothing while no rule is on. Scheduled every fifteen minutes.
 */
class EvaluateRuntimeAlertsCommand extends Command
{
    protected $signature = 'excubia:evaluate-runtime-alerts';

    protected $description = 'Alert on low cache hit ratios, failed mails and notifications, error-log spikes, billing webhooks, failed logins and AI usage';

    public function handle(RuntimeAlertsService $rules, AiAlertsService $aiRules): int
    {
        $hasRuntimeRules = $rules->hasRules();
        $hasAiRules = $aiRules->hasRules();

        if (! $hasRuntimeRules && ! $hasAiRules) {
            $this->components->info('No runtime alert rule is on.');

            return self::SUCCESS;
        }

        $count = 0;

        Project::query()->where('is_enabled', true)->each(function (Project $project) use ($rules, $aiRules, $hasRuntimeRules, $hasAiRules, &$count): void {
            $count += ($hasRuntimeRules ? $rules->evaluate($project) : 0) + ($hasAiRules ? $aiRules->evaluate($project) : 0);
        });

        $this->components->info("Raised {$count} runtime alerts.");

        return self::SUCCESS;
    }
}
