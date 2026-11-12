<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The check summary of a report as columns (#293): the worst check status (fail, warn, pass;
 * null when no check ran), how many checks ran and how many failed. The check statuses live in
 * the report JSON, and reading JSON in SQL differs per driver, so the projects list could not
 * sort by health without them. Existing reports are backfilled from their JSON, the same way
 * ReportHealthService::checkSummary() reads a new one (kept inline: a migration must not change
 * when the service does).
 */
return new class extends Migration
{
    private const array STATUS_RANK = ['fail' => 0, 'warn' => 1, 'pass' => 2];

    public function up(): void
    {
        Schema::table('health_reports', function (Blueprint $table): void {
            $table->string('worst_check_status', 8)->nullable();
            $table->unsignedInteger('checks_count')->default(0);
            $table->unsignedInteger('failed_checks_count')->default(0);
        });

        DB::table('health_reports')->select(['id', 'report'])->chunkById(200, function ($reports): void {
            foreach ($reports as $report) {
                DB::table('health_reports')->where('id', $report->id)->update($this->summary((string) $report->report));
            }
        });
    }

    public function down(): void
    {
        Schema::table('health_reports', function (Blueprint $table): void {
            $table->dropColumn(['worst_check_status', 'checks_count', 'failed_checks_count']);
        });
    }

    /**
     * @return array{worst_check_status: string|null, checks_count: int, failed_checks_count: int}
     */
    private function summary(string $reportJson): array
    {
        $report = json_decode($reportJson, true);
        $worst = null;
        $ranCount = 0;
        $failedCount = 0;

        foreach ((array) (is_array($report) ? ($report['checks'] ?? []) : []) as $check) {
            $status = is_array($check) ? ($check['status'] ?? null) : null;

            if (! is_string($status) || ! array_key_exists($status, self::STATUS_RANK)) {
                continue;
            }

            $ranCount++;

            if ($status === 'fail') {
                $failedCount++;
            }

            if ($worst === null || self::STATUS_RANK[$status] < self::STATUS_RANK[$worst]) {
                $worst = $status;
            }
        }

        return ['worst_check_status' => $worst, 'checks_count' => $ranCount, 'failed_checks_count' => $failedCount];
    }
};
