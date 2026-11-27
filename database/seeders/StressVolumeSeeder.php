<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\StoreRuntimeRollupsAction;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * Seed a local volume benchmark with `php artisan db:seed --class=StressVolumeSeeder`.
 * One busy project and 199 smaller projects exercise both project and fleet queries.
 * Run on a fresh local database. The optional system project and existing users are preserved.
 */
final class StressVolumeSeeder extends Seeder
{
    private const PROJECT_COUNT = 200;

    private const BATCH_SIZE = 400;

    private const RUNTIME_SERIES = [
        ['request', ['route' => 'orders.index', 'method' => 'GET', 'statusClass' => '2xx']],
        ['request', ['route' => 'orders.store', 'method' => 'POST', 'statusClass' => '2xx']],
        ['request', ['route' => 'orders.store', 'method' => 'POST', 'statusClass' => '5xx']],
        ['job', ['job' => 'App\\Jobs\\SendInvoice', 'queue' => 'mail', 'connection' => 'database', 'outcome' => 'processed']],
        ['slowQuery', ['sql' => 'select * from orders where customer_id = ?', 'connection' => 'pgsql']],
    ];

    /**
     * Inserts the fixture atomically, without dispatching ingest side effects or audit listeners.
     */
    public function run(StoreRuntimeRollupsAction $storeRollups): void
    {
        if (! app()->environment('local') || Project::query()->where('is_system', false)->exists()) {
            throw new LogicException('Use StressVolumeSeeder on a fresh local database without user projects.');
        }

        $this->call(PermissionSeeder::class);
        $anchor = CarbonImmutable::now('UTC')->startOfMinute();
        DB::connection()->disableQueryLog();

        DB::transaction(function () use ($anchor, $storeRollups): void {
            [$projectGroups, $userIds] = $this->seedGroups();

            for ($projectNumber = 0; $projectNumber < self::PROJECT_COUNT; $projectNumber++) {
                $projectId = DB::table('projects')->insertGetId([
                    'name' => sprintf('Stress Service %03d', $projectNumber + 1),
                    'slug' => sprintf('stress-service-%03d', $projectNumber + 1),
                    'environment' => $projectNumber % 5 === 4 ? 'staging' : 'production',
                    'visibility' => $projectNumber % 5 === 4 ? ProjectVisibilityEnum::Admins->value : ProjectVisibilityEnum::Everyone->value,
                    'last_report_at' => $anchor,
                    'created_at' => $anchor->subDays(120),
                    'updated_at' => $anchor,
                ]);
                $projectGroups[$projectNumber % count($projectGroups)]->projects()->attach($projectId);
                $this->seedReports($projectId, $anchor, $this->projectShare(50000, 10000, $projectNumber));
                $this->seedExceptions($projectId, $anchor, $this->projectShare(500000, 100000, $projectNumber), $projectNumber === 0 ? 250 : 10);
                $this->seedRuntime($projectId, $anchor, $this->projectShare(1000000, 250000, $projectNumber), $projectNumber === 0 ? 1 : 60, $storeRollups);
                $this->seedSchedule($projectId, $anchor);
                $this->seedActivity($projectId, $anchor, $userIds);
            }
        });
    }

    /** @return array{list<ProjectGroup>, list<int>} */
    private function seedGroups(): array
    {
        $projectGroups = [];
        $userIds = [];
        $passwordHash = Hash::make(Str::random(48));
        $reader = Role::findByName(RoleEnum::Reader->value);

        for ($groupNumber = 1; $groupNumber <= 10; $groupNumber++) {
            $projectGroup = ProjectGroup::query()->createQuietly(['name' => "Stress Portfolio {$groupNumber}"]);
            $userGroup = UserGroup::query()->createQuietly([
                'name' => "Stress Team {$groupNumber}",
                'public_id' => (string) Str::uuid7(),
            ]);
            $userGroup->projectGroups()->attach($projectGroup);
            $userGroup->roles()->attach($reader);
            $projectGroups[] = $projectGroup;

            for ($memberNumber = 1; $memberNumber <= 5; $memberNumber++) {
                $user = User::factory()->createQuietly([
                    'first_name' => 'Stress',
                    'last_name' => "Member {$groupNumber}-{$memberNumber}",
                    'email' => "stress-{$groupNumber}-{$memberNumber}@example.invalid",
                    'password' => $passwordHash,
                ]);
                $userGroup->users()->attach($user);
                $userIds[] = $user->id;
            }
        }

        return [$projectGroups, $userIds];
    }

    private function projectShare(int $fleetTotal, int $busyProjectTotal, int $projectNumber): int
    {
        if ($projectNumber === 0) {
            return $busyProjectTotal;
        }

        $remainder = $fleetTotal - $busyProjectTotal;
        $smallerProjects = self::PROJECT_COUNT - 1;

        return intdiv($remainder, $smallerProjects) + (int) ($projectNumber <= $remainder % $smallerProjects);
    }

    private function seedReports(int $projectId, CarbonImmutable $anchor, int $reportCount): void
    {
        $reportWindowMinutes = 120 * 24 * 60;
        $findings = [];

        for ($findingNumber = 0; $findingNumber < min($reportCount, 150); $findingNumber++) {
            $subject = "stress-fixture/package-{$findingNumber}";
            $findings[] = [
                'checkId' => 'composer-outdated',
                'severity' => 'medium',
                'status' => 'warn',
                'subject' => $subject,
                'message' => "A newer version of {$subject} is available.",
                'fixHint' => "composer update {$subject}",
                'evidenceUrl' => null,
                'fingerprint' => hash('sha256', "composer-outdated|{$subject}|"),
                'details' => ['package' => $subject, 'lockedVersion' => '1.2.0', 'latestVersion' => '1.3.0'],
            ];
        }

        $findingCount = count($findings);
        $this->insertBatches('health_reports', $reportCount, function (int $reportNumber) use ($projectId, $anchor, $reportCount, $reportWindowMinutes, $findings, $findingCount): array {
            $receivedAt = $anchor->subMinutes(intdiv($reportNumber * $reportWindowMinutes, $reportCount));
            $release = 'v1.0.'.intdiv($reportCount - $reportNumber - 1, 20);
            $deployedAt = $reportNumber % 20 === 0 ? $receivedAt : null;
            $finding = $findings[$reportNumber % $findingCount];

            return [
                'project_id' => $projectId,
                'batch_id' => (string) Str::uuid(),
                'received_at' => $receivedAt,
                'generated_at' => $receivedAt,
                'release' => $release,
                'deployed_at' => $deployedAt,
                'worst_severity' => 'medium',
                'medium_count' => 1,
                'worst_check_status' => 'warn',
                'checks_count' => 2,
                'report' => json_encode([
                    'schemaVersion' => 1,
                    'excubiaVersion' => '1.0.0',
                    'generatedAt' => $receivedAt->toIso8601String(),
                    'release' => $release,
                    'deployedAt' => $deployedAt?->toIso8601String(),
                    'project' => ['environment' => 'production', 'php' => ['version' => '8.4.3'], 'laravel' => ['version' => '13.1.0']],
                    'checks' => [
                        ['checkId' => 'laravel-database', 'name' => 'Database', 'status' => 'pass', 'findings' => []],
                        ['checkId' => 'composer-outdated', 'name' => 'Composer outdated', 'status' => 'warn', 'findings' => [$finding]],
                    ],
                    'upgradePlan' => [['stepNumber' => 1, 'message' => $finding['fixHint']]],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ];
        });

        foreach ($findings as $findingNumber => $finding) {
            $lastSeenAt = $anchor->subMinutes(intdiv($findingNumber * $reportWindowMinutes, $reportCount));
            $oldestReportNumber = $findingNumber + $findingCount * intdiv($reportCount - $findingNumber - 1, $findingCount);
            $firstSeenAt = $anchor->subMinutes(intdiv($oldestReportNumber * $reportWindowMinutes, $reportCount));
            $findingId = DB::table('findings')->insertGetId([
                'project_id' => $projectId,
                'fingerprint' => $finding['fingerprint'],
                'check_id' => $finding['checkId'],
                'severity' => $finding['severity'],
                'status' => $findingNumber === 0 ? 'open' : 'resolved',
                'finding' => json_encode($finding, JSON_THROW_ON_ERROR),
                'first_seen_at' => $firstSeenAt,
                'last_seen_at' => $lastSeenAt,
                'resolved_at' => $findingNumber === 0 ? null : $anchor,
                'created_at' => $firstSeenAt,
                'updated_at' => $anchor,
            ]);
            DB::table('finding_events')->insert([
                'finding_id' => $findingId,
                'event' => 'opened',
                'occurred_at' => $firstSeenAt,
            ]);

            if ($findingNumber > 0) {
                DB::table('finding_events')->insert([
                    'finding_id' => $findingId,
                    'event' => 'resolved',
                    'occurred_at' => $anchor,
                ]);
            }
        }
    }

    private function seedExceptions(int $projectId, CarbonImmutable $anchor, int $occurrenceCount, int $groupCount): void
    {
        $groupIds = [];
        $newestBucket = $anchor->startOfHour();

        // Align older occurrences to hours like the retention job does on real data.
        for ($groupNumber = 0; $groupNumber < $groupCount; $groupNumber++) {
            $groupOccurrences = intdiv($occurrenceCount, $groupCount) + (int) ($groupNumber < $occurrenceCount % $groupCount);
            $firstSeenAt = $newestBucket->subHours($groupOccurrences - 1);
            $status = match ($groupNumber % 10) {
                8 => 'resolved',
                9 => 'ignored',
                default => 'open',
            };
            $groupId = DB::table('exception_groups')->insertGetId([
                'project_id' => $projectId,
                'fingerprint' => hash('sha256', "stress-exception-{$groupNumber}"),
                'environment' => 'production',
                'class' => 'RuntimeException',
                'message' => "Warehouse could not confirm shipment {$groupNumber} — Zürich",
                'frames' => json_encode([['file' => 'app/Actions/ShipOrderAction.php', 'line' => 42, 'function' => 'execute', 'inApp' => true]], JSON_THROW_ON_ERROR),
                'previous' => '[]',
                'context_kind' => 'request',
                'context_name' => 'orders.ship',
                'count' => 3 * $groupOccurrences,
                'first_seen_at' => $firstSeenAt,
                'last_seen_at' => $newestBucket,
                'status' => $status,
                'resolved_at' => $status === 'resolved' ? $anchor : null,
                'created_at' => $firstSeenAt,
                'updated_at' => $anchor,
            ]);
            $groupIds[] = $groupId;

            if ($status === 'open') {
                DB::table('exception_open_changes')->insert([
                    'project_id' => $projectId,
                    'exception_group_id' => $groupId,
                    'delta' => 1,
                    'occurred_at' => $firstSeenAt,
                ]);
            }
        }

        $this->insertBatches('exception_occurrences', $occurrenceCount, fn (int $occurrenceNumber): array => [
            'project_id' => $projectId,
            'exception_group_id' => $groupIds[$occurrenceNumber % $groupCount],
            'bucket' => $newestBucket->subHours(intdiv($occurrenceNumber, $groupCount)),
            'count' => 3,
        ]);
    }

    private function seedRuntime(int $projectId, CarbonImmutable $anchor, int $sampleCount, int $minutesPerBucket, StoreRuntimeRollupsAction $storeRollups): void
    {
        $series = array_map(static function (array $definition): array {
            [$type, $dimensions] = $definition;
            $encoded = json_encode($dimensions, JSON_THROW_ON_ERROR);

            return [$type, $encoded, hash('sha256', $encoded), ($dimensions['statusClass'] ?? null) === '5xx'];
        }, self::RUNTIME_SERIES);

        $this->insertBatches('runtime_aggregates', $sampleCount, function (int $sampleNumber) use ($projectId, $anchor, $minutesPerBucket, $series): array {
            $bucketNumber = intdiv($sampleNumber, count($series));
            $bucket = $anchor->subMinutes($minutesPerBucket * $bucketNumber);
            [$type, $dimensions, $dimensionKey, $isError] = $series[$sampleNumber % count($series)];
            $count = $isError ? 1 + $bucketNumber % 3 : 25 + $bucketNumber % 71;

            return [
                'project_id' => $projectId,
                'type' => $type,
                'bucket' => $bucket,
                'sample_rate' => 1.0,
                'dimension_key' => $dimensionKey,
                'dimensions' => $dimensions,
                'count' => $count,
                'error_count' => $isError ? $count : 0,
                'total_ms' => $count * 75.0,
                'max_ms' => 140.0,
                'histogram' => json_encode([0, 0, 0, 0, 0, 0, $count - 1, 1, 0, 0, 0, 0], JSON_THROW_ON_ERROR),
                'release' => 'v1.0.1',
                'created_at' => $bucket,
                'updated_at' => $bucket,
            ];
        }, $storeRollups->execute(...));
    }

    private function seedSchedule(int $projectId, CarbonImmutable $anchor): void
    {
        $task = 'artisan reports:send';
        $taskId = hash('sha256', $task);
        DB::table('scheduled_tasks')->insert([
            'project_id' => $projectId,
            'task_id' => $taskId,
            'task' => $task,
            'expression' => '0 * * * *',
            'timezone' => 'UTC',
            'environments' => '[]',
            'status' => 'on_time',
            'last_run_at' => $anchor->startOfHour(),
            'last_outcome' => 'finished',
        ]);
        $this->insertBatches('scheduled_task_runs', 24, fn (int $runNumber): array => [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'bucket' => $anchor->startOfHour()->subHours($runNumber),
            'outcome' => 'finished',
            'count' => 1,
            'sample_rate' => 1.0,
        ]);
    }

    /** @param list<int> $userIds */
    private function seedActivity(int $projectId, CarbonImmutable $anchor, array $userIds): void
    {
        $this->insertBatches('activity_log', 50, fn (int $activityNumber): array => [
            'log_name' => 'projects',
            'description' => 'updated',
            'event' => 'updated',
            'subject_id' => (string) $projectId,
            'subject_type' => Project::class,
            'causer_id' => (string) $userIds[$activityNumber],
            'causer_type' => User::class,
            'created_at' => $anchor->subDays($activityNumber * 2),
            'updated_at' => $anchor->subDays($activityNumber * 2),
        ]);
    }

    /**
     * Generates only one insert batch at a time to bound PHP memory.
     *
     * @template Row of array<string, mixed>
     *
     * @param  Closure(int): Row  $makeRow
     * @param  (Closure(list<Row>): void)|null  $afterInsert
     */
    private function insertBatches(string $table, int $rowCount, Closure $makeRow, ?Closure $afterInsert = null): void
    {
        for ($offset = 0; $offset < $rowCount; $offset += self::BATCH_SIZE) {
            $rows = [];
            $end = min($rowCount, $offset + self::BATCH_SIZE);

            for ($rowNumber = $offset; $rowNumber < $end; $rowNumber++) {
                $rows[] = $makeRow($rowNumber);
            }

            DB::table($table)->insert($rows);
            $afterInsert?->__invoke($rows);
        }
    }
}
