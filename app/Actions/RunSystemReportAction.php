<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\HealthReport;
use App\Models\Project;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Renderers\JsonReportRenderer;
use Qiick\Excubia\Services\HealthCheckRunnerService;

/**
 * Runs the excubia-core checks on this Excubia install and stores the report on the system
 * project (#70), through the same action as a pushed report: no HTTP push and no token. A data
 * source that is down becomes a skipped check, as with `excubia check`.
 */
class RunSystemReportAction
{
    public function __construct(
        private StorePushedReportAction $storeReport,
        private HttpClientContract $httpClient,
    ) {}

    /**
     * @return HealthReport|null null when there is no enabled system project
     */
    public function execute(): ?HealthReport
    {
        $project = Project::query()->where('is_system', true)->where('is_enabled', true)->first();

        if ($project === null) {
            return null;
        }

        $configuration = (new ExcubiaConfigurationLoader)->load(null, base_path());
        $result = (new HealthCheckRunnerService($this->httpClient, new DateTimeImmutable('now', new DateTimeZone('UTC'))))
            ->run(base_path(), $configuration, null, null, false, false);

        /** @var array<string, mixed> $report */
        $report = json_decode((new JsonReportRenderer)->render($result['report']), true, flags: JSON_THROW_ON_ERROR);

        return $this->storeReport->execute($project, (string) Str::uuid(), Date::now(), $report);
    }
}
