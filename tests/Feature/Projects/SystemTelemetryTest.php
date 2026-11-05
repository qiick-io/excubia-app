<?php

declare(strict_types=1);

use App\Actions\StoreSystemTelemetryAction;
use App\Models\Project;
use App\Services\SelfMonitoringService;
use Database\Seeders\SystemProjectSeeder;
use Illuminate\Support\Facades\File;
use Qiick\Excubia\Laravel\ExceptionCollectorService;
use Qiick\Excubia\Services\RuntimeBufferService;

/**
 * The system project records this install's own runtime and exceptions (#84): the core's
 * buffers on this app are moved into the system project, with no HTTP push.
 */
beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/excubia-self-'.uniqid();
    File::makeDirectory($this->storage, recursive: true);
    app()->useStoragePath($this->storage);
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
