<?php

declare(strict_types=1);

use App\Actions\StoreSystemTelemetryAction;
use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Services\SelfMonitoringService;
use Database\Seeders\SystemProjectSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\ServerResourcesContract;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum as CoreRuntimeTypeEnum;
use Qiick\Excubia\Laravel\ExceptionCollectorService;
use Qiick\Excubia\Services\RuntimeBufferService;

/**
 * The system project records this install's own runtime and exceptions (#84): the core's
 * buffers on this app are moved into the system project, with no HTTP push. Its runtime types
 * come from `EXCUBIA_SELF_MONITORING_TYPES` or the core's defaults (#219), and the telemetry run
 * takes the `server` and `health` samples that `excubia:push-runtime` takes elsewhere (#220).
 *
 * The drain tests record only `requests`, so no machine sample is mixed into their counts.
 */
beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/excubia-self-'.uniqid();
    File::makeDirectory($this->storage, recursive: true);
    app()->useStoragePath($this->storage);
    config(['excubia.self_monitoring_types' => 'requests']);
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

function bufferSystemTelemetry(): void
{
    (new RuntimeBufferService(storage_path('excubia/runtime'), 1_048_576))->append([
        ['type' => 'request', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'route' => 'dashboard', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 3, 'errorCount' => 0, 'totalMs' => 300, 'maxMs' => 150],
    ]);
    (new RuntimeBufferService(storage_path('excubia/exceptions'), 1_048_576))->append([[
        'class' => 'RuntimeException', 'fingerprint' => str_repeat('e', 64), 'message' => 'Boom for [email]',
        'frames' => [['file' => 'app/Actions/X.php', 'line' => 3, 'function' => 'X::run', 'inApp' => true]], 'previous' => [],
        'context' => ['kind' => 'request', 'name' => 'dashboard'], 'environment' => 'production', 'count' => 2,
        'firstSeen' => '2026-11-05T11:58:00Z', 'lastSeen' => '2026-11-05T11:59:00Z',
    ]]);
}

it('moves the buffered runtime and exceptions into the system project', function () {
    $this->seed(SystemProjectSeeder::class);
    $project = Project::query()->where('is_system', true)->sole();
    bufferSystemTelemetry();

    expect(app(StoreSystemTelemetryAction::class)->execute())->toBe(['runtime' => 1, 'exceptions' => 1]);

    expect($project->runtimeAggregates()->sole()->dimensions['route'])->toBe('dashboard')
        ->and($project->exceptionGroups()->sole())->count->toBe(2)->message->toBe('Boom for [email]');

    // The batches are gone: a second run stores nothing.
    $this->artisan('excubia:system-telemetry')->assertSuccessful();
    expect($project->runtimeAggregates()->sole()->count)->toBe(3);
});

it('keeps the buffers while there is no enabled system project', function () {
    $this->seed(SystemProjectSeeder::class);
    Project::query()->where('is_system', true)->update(['is_enabled' => false]);
    bufferSystemTelemetry();

    expect(app(StoreSystemTelemetryAction::class)->execute())->toBe(['runtime' => 0, 'exceptions' => 0])
        ->and(File::exists(storage_path('excubia/runtime/buffer.ndjson')))->toBeTrue();
});

it('registers the core collectors on the app only when self-monitoring is on', function () {
    app()->forgetInstance(ExceptionCollectorService::class);

    config(['excubia.self_monitoring' => false]);
    app(SelfMonitoringService::class)->register(app());
    expect(app()->bound(ExceptionCollectorService::class))->toBeFalse();

    config(['excubia.self_monitoring' => true]);
    app(SelfMonitoringService::class)->register(app());
    expect(app()->bound(ExceptionCollectorService::class))->toBeTrue();
});

/**
 * Fixed machine readings, so no test reads the real host.
 */
function fakeServerReadings(): void
{
    app()->instance(ServerResourcesContract::class, new class implements ServerResourcesContract
    {
        public function loadAverages(): ?array
        {
            return [0.5, 0.4, 0.3];
        }

        public function cpuCoreCount(): ?int
        {
            return 4;
        }

        public function memory(): ?array
        {
            return ['usedBytes' => 1024 * 1048576, 'totalBytes' => 4096 * 1048576];
        }

        public function cpuLimit(): ?float
        {
            return null;
        }

        public function isContainer(): bool
        {
            return false;
        }
    });
    app()->instance(DiskSpaceContract::class, new class implements DiskSpaceContract
    {
        public function usedPercent(string $directory): ?int
        {
            return 25;
        }

        public function usage(string $directory): ?array
        {
            return ['usedBytes' => 10 * 1073741824, 'totalBytes' => 40 * 1073741824];
        }
    });
}

it('turns on every type the installed core collects and the app stores by default, cache sampled', function () {
    config(['excubia.self_monitoring_types' => null]);
    $selection = app(SelfMonitoringService::class)->typeSelection();
    $types = collect($selection['types'])->keyBy('type');

    expect($selection['source'])->toBe('default')
        ->and($selection['unknown'])->toBe([])
        ->and($types->every(fn (array $type): bool => $type['isEnabled']))->toBeTrue()
        ->and($types->keys()->all())->toContain('requests', 'queueWait', 'mail', 'notifications', 'logLevels', 'deprecations', 'duplicateQueries', 'commands', 'server', 'health')
        ->and($types['cache']['sampleRate'])->toBe(0.1)
        ->and($types->count())->toBe(collect(CoreRuntimeTypeEnum::cases())->filter(fn (CoreRuntimeTypeEnum $type): bool => RuntimeTypeEnum::tryFrom($type->value) !== null)->count());

    $runtime = app(SelfMonitoringService::class)->runtimeConfiguration(app(SelfMonitoringService::class)->configuration(app()));

    expect($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Server))->toBeTrue()
        ->and($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Health))->toBeTrue()
        ->and($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Command))->toBeTrue()
        ->and($runtime->sampleRate(CoreRuntimeTypeEnum::Cache))->toBe(0.1);
});

it('reads the types from EXCUBIA_SELF_MONITORING_TYPES, not from the shipped file an upgrade overwrites', function () {
    // The shipped file only switches runtime and exceptions on: the types never live in it.
    $shipped = json_decode((string) file_get_contents(base_path(SelfMonitoringService::CONFIG_PATH)), true, flags: JSON_THROW_ON_ERROR);
    expect($shipped['runtime'])->toBe(['enabled' => true]);

    config(['excubia.self_monitoring_types' => ' server, health ,notification, typo ']);
    $service = app(SelfMonitoringService::class);
    $selection = $service->typeSelection();
    $runtime = $service->runtimeConfiguration($service->configuration(app()));

    expect($selection['source'])->toBe('environment')
        ->and($selection['unknown'])->toBe(['typo'])
        ->and(collect($selection['types'])->where('isEnabled', true)->pluck('type')->all())->toBe(['notifications', 'server', 'health'])
        ->and($runtime)->toBeInstanceOf(RuntimeConfigurationData::class)
        ->and($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Server))->toBeTrue()
        ->and($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Notification))->toBeTrue()
        // A default-on core type is off when the list leaves it out.
        ->and($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Request))->toBeFalse();

    config(['excubia.self_monitoring_types' => 'all']);
    expect(collect($service->typeSelection()['types'])->every(fn (array $type): bool => $type['isEnabled']))->toBeTrue();
});

it('stores a server sample of this machine on the system project', function () {
    config(['excubia.self_monitoring' => true, 'excubia.self_monitoring_types' => 'server']);
    Date::setTestNow('2026-11-09 10:15:30');
    fakeServerReadings();
    $this->seed(SystemProjectSeeder::class);
    $project = Project::query()->where('is_system', true)->sole();

    $this->artisan('excubia:system-telemetry')->assertSuccessful();

    $server = $project->runtimeAggregates()->where('type', 'server')->sole();

    expect($server->bucket->toIso8601ZuluString())->toBe('2026-11-09T10:15:00Z')
        ->and($server->dimensions['server'])->toMatch('/^[0-9a-f]{12}$/')
        ->and($server->metrics['memoryUsedMb'])->toBe(1024)
        ->and($server->metrics['diskTotalGb'])->toEqual(40)
        ->and($project->runtimeAggregates()->where('type', '!=', 'server')->exists())->toBeFalse();
});

it('stores one health sample per check and minute on the system project', function () {
    config(['excubia.self_monitoring' => true, 'excubia.self_monitoring_types' => 'health']);
    Date::setTestNow('2026-11-09 10:15:30');
    fakeServerReadings();
    $this->seed(SystemProjectSeeder::class);
    $project = Project::query()->where('is_system', true)->sole();

    $this->artisan('excubia:system-telemetry')->assertSuccessful();
    // The second run of the same minute takes no second sample.
    $this->artisan('excubia:system-telemetry')->assertSuccessful();

    $health = $project->runtimeAggregates()->where('type', 'health')->get();

    expect($health->pluck('dimensions.checkId')->sort()->values()->all())->toBe(collect(RuntimeConfigurationData::HEALTH_CHECK_IDS)->sort()->values()->all())
        ->and($health->every(fn ($aggregate): bool => $aggregate->count === 1))->toBeTrue()
        ->and($health->pluck('metrics.status')->unique()->diff(['pass', 'warn', 'fail', 'skipped'])->all())->toBe([]);
});

it('takes no machine sample when self-monitoring is off', function () {
    config(['excubia.self_monitoring' => false, 'excubia.self_monitoring_types' => 'server,health']);
    fakeServerReadings();
    $this->seed(SystemProjectSeeder::class);

    $this->artisan('excubia:system-telemetry')->assertSuccessful();

    expect(Project::query()->where('is_system', true)->sole()->runtimeAggregates()->exists())->toBeFalse();
});
