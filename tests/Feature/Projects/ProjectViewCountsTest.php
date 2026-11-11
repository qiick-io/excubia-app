<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The counts and severity markers on the grouped project tabs (#275): open exception groups
 * and active findings, on every tab of the project page.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-11 09:00:00');
});

function viewCountsException(Project $project, string $fingerprint, string $status): void
{
    ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat($fingerprint, 64), 'environment' => 'production',
        'class' => 'RuntimeException', 'message' => 'Payment failed', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(), 'status' => $status,
    ]);
}

function viewCountsFinding(Project $project, string $subject, string $severity, array $attributes = []): Finding
{
    return $project->findings()->create([
        'fingerprint' => hash('sha256', $subject), 'check_id' => 'composer-audit', 'severity' => $severity, 'status' => 'open',
        'finding' => ['checkId' => 'composer-audit', 'checkName' => 'Composer audit', 'severity' => $severity, 'status' => 'fail', 'subject' => $subject, 'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null],
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(),
        ...$attributes,
    ]);
}

it('counts open exception groups and active findings, with the worst severity', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    viewCountsException($project, 'a', 'open');
    viewCountsException($project, 'b', 'open');
    viewCountsException($project, 'c', 'resolved');
    viewCountsFinding($project, 'one', 'medium');
    viewCountsFinding($project, 'two', 'low');
    viewCountsFinding($project, 'three', 'critical', ['dismissed_at' => Date::now()]);
    viewCountsFinding($project, 'four', 'high', ['status' => 'resolved']);

    $this->actingAs($admin)->get(route('projects.view', ['project' => 'shop', 'view' => 'runtime']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('viewCounts.exceptions', ['count' => 2, 'severity' => 'critical'])
            ->where('viewCounts.findings', ['count' => 2, 'severity' => 'warning']));
});

it('marks findings as critical from a high or critical one, and leaves a clean project unmarked', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    Project::factory()->create(['slug' => 'clean']);
    viewCountsFinding($project, 'one', 'high');

    $this->actingAs($admin)->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page->where('viewCounts.findings', ['count' => 1, 'severity' => 'critical']));

    $this->actingAs($admin)->get(route('projects.show', 'clean'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('viewCounts.exceptions', ['count' => 0, 'severity' => null])
            ->where('viewCounts.findings', ['count' => 0, 'severity' => null]));
});
