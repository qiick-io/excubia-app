<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Models\AlertLog;
use App\Models\Project;
use App\Services\AlertDispatcherService;
use App\Services\HealthStatusService;
use App\Services\ReportFindingsService;
use Illuminate\Support\Facades\DB;

/**
 * Sends `health.failed` and `health.recovered` (#115) after a runtime batch with health samples,
 * as HealthStatusService::transition() says. The check's last health alert in the history is its
 * state (suppressed alerts count too, so a mute or a maintenance window does not re-alert later),
 * and the subject is the check id, so "mute this issue" mutes one check. The project row is
 * locked first, so two servers pushing at once cannot both send the same alert.
 */
class EvaluateHealthAlertsAction
{
    public function __construct(
        private HealthStatusService $health,
        private ReportFindingsService $findings,
        private AlertDispatcherService $alerts,
    ) {}

    public function execute(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            foreach ($this->health->checks($project) as $check) {
                $last = AlertLog::query()
                    ->where('project_id', $project->id)
                    ->whereIn('event', [AlertEventEnum::HealthFailed->value, AlertEventEnum::HealthRecovered->value])
                    ->where('subject', $check['checkId'])
                    ->latest('id')
                    ->value('event');
                $event = $this->health->transition(array_column($check['servers'], 'readings'), $last === AlertEventEnum::HealthFailed->value);

                if ($event === null) {
                    continue;
                }

                $servers = count($check['servers']);
                $failing = count(array_filter($check['servers'], fn (array $server): bool => $server['status'] === 'fail'));

                if ($event === AlertEventEnum::HealthRecovered) {
                    $this->alerts->dispatch($event, $project, "{$check['checkId']} no longer fails ({$check['status']})", [
                        'checkId' => $check['checkId'],
                        'status' => $check['status'],
                        'servers' => $servers,
                    ], subject: $check['checkId']);

                    continue;
                }

                $severity = SeverityEnum::tryFrom($check['severity']);
                $this->alerts->dispatch($event, $project, trim("{$check['checkId']} fails on {$failing} of {$servers} servers: ".$this->message($project, $check['fingerprints']), ': '), [
                    'checkId' => $check['checkId'],
                    'status' => 'fail',
                    'severity' => $check['severity'],
                    'findingCount' => $check['findingCount'],
                    'failingServers' => $failing,
                    'servers' => $servers,
                    'fingerprints' => $check['fingerprints'],
                ], $severity === SeverityEnum::Info ? null : $severity, $check['checkId']);
            }
        });
    }

    /**
     * "subject: message" of the latest report's first finding with one of the fingerprints: the
     * sample never carries a message. Empty when the report has none of them (a new failure).
     *
     * @param  list<string>  $fingerprints
     */
    private function message(Project $project, array $fingerprints): string
    {
        $report = $project->healthReports()->latest('received_at')->latest('id')->first();

        if ($report === null || $fingerprints === []) {
            return '';
        }

        foreach ($this->findings->issues($report) as $finding) {
            if (in_array($finding['fingerprint'] ?? null, $fingerprints, true)) {
                return trim(($finding['subject'] ?? '').': '.($finding['message'] ?? ''), ': ');
            }
        }

        return '';
    }
}
