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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sends `health.failed` and `health.recovered` (#115) after a runtime batch with health samples,
 * and every minute from excubia:evaluate-schedules, as HealthStatusService::transition() says
 * for the servers that are not stale. The check's last health alert in the history is its state
 * (suppressed alerts count too, so a mute or a maintenance window does not re-alert later), and
 * the subject is the check id, so "mute this issue" mutes one check. While a check fails, a new
 * set of failing fingerprints alerts `health.failed` again (#143). A server silent on every
 * check for STALE_MINUTES alerts `health.stale` once per silence, its name as the subject. The
 * project row is locked first, so two servers pushing at once cannot both send the same alert.
 *
 * ponytail: a re-alert follows the latest reading, without the debounce of the first failure;
 * debounce it like transition() if flapping findings make it noisy.
 */
class EvaluateHealthAlertsAction
{
    public function __construct(
        private HealthStatusService $health,
        private ReportFindingsService $findings,
        private AlertDispatcherService $alerts,
    ) {}

    public function execute(Project $project, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        DB::transaction(function () use ($project, $now): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $checks = $this->health->checks($project, $now);

            foreach ($checks as $check) {
                $this->evaluateCheck($project, $check);
            }

            foreach ($this->health->staleServers($checks) as $server => $lastSampleAt) {
                // This silence is alerted when an alert came at least STALE_MINUTES after its
                // last sample: a sample of the minute before an older alert starts a new one.
                $isAlerted = AlertLog::query()
                    ->where('project_id', $project->id)
                    ->where('event', AlertEventEnum::HealthStale->value)
                    ->where('subject', $server)
                    ->where('created_at', '>=', CarbonImmutable::parse($lastSampleAt)->addMinutes(HealthStatusService::STALE_MINUTES))
                    ->exists();

                if (! $isAlerted) {
                    $this->alerts->dispatch(AlertEventEnum::HealthStale, $project, "no health sample from {$server} since ".CarbonImmutable::parse($lastSampleAt)->toDateTimeString(), [
                        'server' => $server,
                        'lastSampleAt' => $lastSampleAt,
                    ], subject: $server);
                }
            }
        });
    }

    /**
     * @param  array{checkId: string, status: string, severity: string, findingCount: int, servers: list<array{server: string, status: string, severity: string, findingCount: int, fingerprints: list<string>, lastSampleAt: string, isStale: bool, readings: list<string>}>}  $check
     */
    private function evaluateCheck(Project $project, array $check): void
    {
        $last = AlertLog::query()
            ->where('project_id', $project->id)
            ->whereIn('event', [AlertEventEnum::HealthFailed->value, AlertEventEnum::HealthRecovered->value])
            ->where('subject', $check['checkId'])
            ->latest('id')
            ->first(['event', 'fingerprints']);
        $isFailing = $last?->event === AlertEventEnum::HealthFailed->value;
        $servers = array_values(array_filter($check['servers'], fn (array $server): bool => ! $server['isStale']));
        $fingerprints = $this->health->failingFingerprints($servers);
        $event = $this->health->transition(array_column($servers, 'readings'), $isFailing);

        if ($event === null && $isFailing && $fingerprints !== [] && $fingerprints !== ($last->fingerprints ?? [])) {
            $event = AlertEventEnum::HealthFailed;
        }

        if ($event === null) {
            return;
        }

        if ($event === AlertEventEnum::HealthRecovered) {
            $this->alerts->dispatch($event, $project, "{$check['checkId']} no longer fails ({$check['status']})", [
                'checkId' => $check['checkId'],
                'status' => $check['status'],
                'servers' => count($servers),
            ], subject: $check['checkId']);

            return;
        }

        $failing = count(array_filter($servers, fn (array $server): bool => $server['status'] === 'fail'));
        $severity = SeverityEnum::tryFrom($check['severity']);
        $this->alerts->dispatch($event, $project, trim("{$check['checkId']} fails on {$failing} of ".count($servers).' servers: '.$this->message($project, $fingerprints), ': '), [
            'checkId' => $check['checkId'],
            'status' => 'fail',
            'severity' => $check['severity'],
            'findingCount' => $check['findingCount'],
            'failingServers' => $failing,
            'servers' => count($servers),
            'fingerprints' => $fingerprints,
        ], $severity === SeverityEnum::Info ? null : $severity, $check['checkId'], $fingerprints);
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
