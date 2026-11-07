<?php

declare(strict_types=1);

use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fleet pages (#19, #20, #23): upgrade readiness, advisory exposure and the end-of-life calendar
 * across the projects the user may see, from the latest report of each.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-07 12:00:00');
});

/**
 * A finding of one core check as the report schema sends it.
 *
 * @param  array<string, mixed>  $details
 * @return array<string, mixed>
 */
function fleetFinding(string $checkId, string $status, string $severity, string $subject, array $details = [], ?string $advisoryId = null): array
{
    return array_filter([
        'checkId' => $checkId,
        'severity' => $severity,
        'status' => $status,
        'subject' => $subject,
        'message' => 'Finding of '.$subject,
        'fixHint' => '',
        'evidenceUrl' => $advisoryId === null ? null : 'https://packagist.org/security-advisories/'.$advisoryId,
        'advisoryId' => $advisoryId,
        'details' => $details === [] ? null : $details,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * Stores a report for the project with the given checks (id => findings) and runtimes.
 *
 * @param  array<string, list<array<string, mixed>>>  $checks
 * @param  array<string, mixed>  $runtimes
 */
function fleetReport(Project $project, array $checks, array $runtimes = [], string $receivedAt = '2026-11-07 08:00:00', int $planSteps = 0): void
{
    $statusOf = function (array $findings): string {
        $statuses = array_column($findings, 'status');

        return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'pass');
    };

    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $receivedAt,
        'generated_at' => $receivedAt,
        'report' => [
            'schemaVersion' => 1,
            'project' => ['php' => null, 'laravel' => null, 'node' => null, ...$runtimes],
            'checks' => array_map(fn (string $checkId): array => [
                'checkId' => $checkId,
                'name' => $checkId,
                'status' => $statusOf($checks[$checkId]),
                'findings' => $checks[$checkId],
            ], array_keys($checks)),
            'upgradePlan' => $planSteps === 0 ? [] : array_map(fn (int $step): array => ['stepNumber' => $step, 'message' => 'Step '.$step], range(1, $planSteps)),
        ],
    ]);
}

/**
 * @return array{version: string, cycle: string|null, securitySupportEndsOn: string|null, activeSupportEndsOn: string|null}
 */
function fleetRuntime(string $version, ?string $cycle, ?string $securityEndsOn, ?string $activeEndsOn = null): array
{
    return ['version' => $version, 'cycle' => $cycle, 'securitySupportEndsOn' => $securityEndsOn, 'activeSupportEndsOn' => $activeEndsOn];
}

function fleetReader(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Reader->value);
}

function fleetAdmin(): User
{
    return User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
}

it('shows how many projects can move to the next Laravel major and what blocks the others', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    fleetReport($shop, ['laravel-next-blocker' => [
        fleetFinding('laravel-next-blocker', 'fail', 'medium', 'spatie/laravel-ray', ['package' => 'spatie/laravel-ray', 'lockedVersion' => '1.40.0', 'targetMajor' => 14]),
        fleetFinding('laravel-next-blocker', 'warn', 'low', 'livewire/livewire', ['package' => 'livewire/livewire', 'lockedVersion' => '3.5.0', 'targetMajor' => 14]),
    ]], ['laravel' => fleetRuntime('v13.1.0', '13', '2028-03-17')], planSteps: 2);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    // Private packages are counted, not checked: the project is still ready.
    fleetReport($blog, ['laravel-next-blocker' => [
        fleetFinding('laravel-next-blocker', 'skipped', 'info', 'private packages', ['count' => 2]),
    ]], ['laravel' => fleetRuntime('13.4.0', '13', '2028-03-17')]);
    $legacy = Project::factory()->create(['name' => 'Legacy', 'slug' => 'legacy']);
    fleetReport($legacy, ['laravel-next-blocker' => []], ['laravel' => fleetRuntime('12.0.0', '12', '2027-02-24')]);
    // Laravel not locked: the check skipped without a count.
    $tool = Project::factory()->create(['name' => 'Tool', 'slug' => 'tool']);
    fleetReport($tool, ['laravel-next-blocker' => [fleetFinding('laravel-next-blocker', 'skipped', 'info', 'laravel/framework')]]);
    Project::factory()->create(['name' => 'New', 'slug' => 'new']);

    $this->actingAs(fleetReader())->get(route('fleet.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet/index')
            ->where('view', 'readiness')
            ->where('advisories', null)
            ->where('calendar', null)
            ->where('readiness.targets', [
                ['targetMajor' => 13, 'ready' => 1, 'total' => 1],
                ['targetMajor' => 14, 'ready' => 1, 'total' => 2],
            ])
            ->where('readiness.unknown', 2)
            ->where('readiness.projects.0.project', ['slug' => 'shop', 'name' => 'Shop'])
            ->where('readiness.projects.0.status', 'blocked')
            ->where('readiness.projects.0.targetMajor', 14)
            ->where('readiness.projects.0.laravelVersion', '13.1.0')
            ->where('readiness.projects.0.planSteps', 2)
            ->where('readiness.projects.0.blockers', [
                ['package' => 'spatie/laravel-ray', 'lockedVersion' => '1.40.0', 'isPreReleaseOnly' => false],
                ['package' => 'livewire/livewire', 'lockedVersion' => '3.5.0', 'isPreReleaseOnly' => true],
            ])
            ->where('readiness.projects.1.project.slug', 'blog')
            ->where('readiness.projects.1.status', 'ready')
            ->where('readiness.projects.1.uncheckedPackages', 2)
            ->where('readiness.projects.2.project.slug', 'legacy')
            ->where('readiness.projects.2.status', 'ready')
            ->where('readiness.projects.2.targetMajor', 13)
            ->where('readiness.projects.3.project.slug', 'new')
            ->where('readiness.projects.3.status', 'unknown')
            ->where('readiness.projects.3.hasReport', false)
            ->where('readiness.projects.4.project.slug', 'tool')
            ->where('readiness.projects.4.status', 'unknown'));
});

it('lists the projects exposed to each advisory or package with locked and fixed versions', function () {
    $guzzle = ['package' => 'guzzlehttp/guzzle', 'affectedVersions' => '>=7.0.0,<7.4.5', 'fixedVersions' => ['7.4.5']];
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    fleetReport($shop, [
        'composer-advisories' => [fleetFinding('composer-advisories', 'fail', 'critical', 'guzzlehttp/guzzle', [...$guzzle, 'lockedVersion' => '7.4.0'], 'PKSA-1234')],
        'npm-advisories' => [
            fleetFinding('npm-advisories', 'warn', 'medium', 'axios', ['package' => 'axios', 'lockedVersion' => '1.6.0', 'fixedVersions' => []], 'GHSA-wf5p-g6vw-rhxx'),
            fleetFinding('npm-advisories', 'skipped', 'info', 'private packages', ['count' => 1]),
        ],
        'composer-abandoned' => [fleetFinding('composer-abandoned', 'warn', 'high', 'swiftmailer/swiftmailer', ['package' => 'swiftmailer/swiftmailer', 'lockedVersion' => '6.3.0', 'replacement' => 'symfony/mailer'])],
    ]);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    // An older report of Blog does not count: only the latest one does.
    fleetReport($blog, ['composer-stale' => [fleetFinding('composer-stale', 'warn', 'low', 'jenssegers/agent', ['package' => 'jenssegers/agent', 'lockedVersion' => '2.6.4'])]], receivedAt: '2026-11-01 08:00:00');
    fleetReport($blog, ['composer-advisories' => [fleetFinding('composer-advisories', 'fail', 'high', 'guzzlehttp/guzzle', [...$guzzle, 'lockedVersion' => 'v7.2.0'], 'PKSA-1234')]]);

    $this->actingAs(fleetReader())->get(route('fleet.view', ['view' => 'advisories', 'search' => 'PKSA']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet/index')
            ->where('view', 'advisories')
            ->where('readiness', null)
            ->where('search', 'PKSA')
            ->has('advisories', 3)
            ->where('advisories.0', [
                'key' => 'composer-advisories|guzzlehttp/guzzle|PKSA-1234',
                'kind' => 'advisory',
                'ecosystem' => 'composer',
                'package' => 'guzzlehttp/guzzle',
                'advisoryId' => 'PKSA-1234',
                'evidenceUrl' => 'https://packagist.org/security-advisories/PKSA-1234',
                'replacement' => null,
                'severity' => 'critical',
                'projects' => [
                    ['slug' => 'blog', 'name' => 'Blog', 'lockedVersion' => '7.2.0', 'fixedVersions' => ['7.4.5'], 'severity' => 'high'],
                    ['slug' => 'shop', 'name' => 'Shop', 'lockedVersion' => '7.4.0', 'fixedVersions' => ['7.4.5'], 'severity' => 'critical'],
                ],
            ])
            ->where('advisories.1.kind', 'abandoned')
            ->where('advisories.1.replacement', 'symfony/mailer')
            ->where('advisories.2.ecosystem', 'npm')
            ->where('advisories.2.advisoryId', 'GHSA-wf5p-g6vw-rhxx'));
});

it('shows the PHP, Laravel and Node support windows per project, soonest end first', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    fleetReport($shop, [], [
        'php' => fleetRuntime('8.4.3', '8.4', '2028-12-31', '2026-12-31'),
        'laravel' => fleetRuntime('v13.1.0', '13', '2028-03-17', '2027-09-17'),
    ]);
    $legacy = Project::factory()->create(['name' => 'Legacy', 'slug' => 'legacy']);
    fleetReport($legacy, [], [
        'php' => fleetRuntime('8.1.0', '8.1', '2025-12-31', '2023-11-25'),
        'node' => fleetRuntime('22', '22', '2027-04-30'),
    ]);
    // No cycle matched: no dates, so no row.
    $tool = Project::factory()->create(['name' => 'Tool', 'slug' => 'tool']);
    fleetReport($tool, [], ['php' => fleetRuntime('8.5.0', null, null)]);

    $this->actingAs(fleetReader())->get(route('fleet.view', 'eol'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet/index')
            ->where('view', 'eol')
            ->where('calendar', [
                [
                    'project' => ['slug' => 'legacy', 'name' => 'Legacy'],
                    'nearestEndsOn' => '2025-12-31',
                    'runtimes' => [
                        ['name' => 'php', 'version' => '8.1.0', 'cycle' => '8.1', 'securitySupportEndsOn' => '2025-12-31', 'activeSupportEndsOn' => '2023-11-25'],
                        ['name' => 'node', 'version' => '22', 'cycle' => '22', 'securitySupportEndsOn' => '2027-04-30', 'activeSupportEndsOn' => null],
                    ],
                ],
                [
                    'project' => ['slug' => 'shop', 'name' => 'Shop'],
                    'nearestEndsOn' => '2028-03-17',
                    'runtimes' => [
                        ['name' => 'php', 'version' => '8.4.3', 'cycle' => '8.4', 'securitySupportEndsOn' => '2028-12-31', 'activeSupportEndsOn' => '2026-12-31'],
                        ['name' => 'laravel', 'version' => '13.1.0', 'cycle' => '13', 'securitySupportEndsOn' => '2028-03-17', 'activeSupportEndsOn' => '2027-09-17'],
                    ],
                ],
            ]));
});

it('only shows the projects the user may see', function (string $view) {
    $runtimes = ['php' => fleetRuntime('8.1.0', '8.1', '2025-12-31')];
    $checks = [
        'laravel-next-blocker' => [],
        'composer-abandoned' => [fleetFinding('composer-abandoned', 'warn', 'high', 'swiftmailer/swiftmailer', ['package' => 'swiftmailer/swiftmailer', 'lockedVersion' => '6.3.0', 'replacement' => null])],
    ];
    fleetReport(Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']), $checks, [...$runtimes, 'laravel' => fleetRuntime('13.0.0', '13', '2028-03-17')]);
    fleetReport(Project::factory()->create(['name' => 'Secret', 'slug' => 'secret', 'visibility' => ProjectVisibilityEnum::Admins]), $checks, [...$runtimes, 'laravel' => fleetRuntime('13.0.0', '13', '2028-03-17')]);

    $slugs = fn (Assert $page): array => match ($view) {
        'readiness' => array_column(array_column($page->toArray()['props']['readiness']['projects'], 'project'), 'slug'),
        'advisories' => array_column($page->toArray()['props']['advisories'][0]['projects'], 'slug'),
        default => array_column(array_column($page->toArray()['props']['calendar'], 'project'), 'slug'),
    };

    $this->actingAs(fleetReader())->get(route('fleet.view', $view))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect($slugs($page))->toBe(['shop']));

    $this->actingAs(fleetAdmin())->get(route('fleet.view', $view))
        ->assertInertia(fn (Assert $page) => expect($slugs($page))->toBe(['secret', 'shop']));
})->with(['readiness', 'advisories', 'eol']);

it('requires can-show-projects', function () {
    $this->get(route('fleet.show'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('fleet.show'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('fleet.view', 'eol'))->assertForbidden();
});

it('returns 404 for an unknown fleet view', function () {
    $this->actingAs(fleetReader())->get('/fleet/unknown')->assertNotFound();
});
