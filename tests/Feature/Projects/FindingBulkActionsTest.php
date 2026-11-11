<?php

declare(strict_types=1);

use App\Enums\FindingEventEnum;
use App\Enums\RoleEnum;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

/**
 * Bulk assign on the Findings tab (#276): each finding authorized, assigned, logged and given
 * its history event on its own.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-11 09:00:00');
});

function bulkFinding(Project $project, string $subject): Finding
{
    return $project->findings()->create([
        'fingerprint' => hash('sha256', $subject), 'check_id' => 'composer-audit', 'severity' => 'high', 'status' => 'open',
        'finding' => ['checkId' => 'composer-audit', 'checkName' => 'Composer audit', 'severity' => 'high', 'status' => 'fail', 'subject' => $subject, 'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null],
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(),
    ]);
}

it('assigns the selected findings, with an activity and a history event each', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    $findings = [bulkFinding($project, 'vendor/one'), bulkFinding($project, 'vendor/two')];
    $ids = array_map(fn (Finding $finding): int => $finding->id, $findings);

    $this->actingAs($admin)
        ->put(route('projects.findings.bulk.assignee', $project), ['ids' => $ids, 'assigned_user_id' => $admin->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Finding::query()->whereIn('id', $ids)->pluck('assigned_user_id')->unique()->all())->toBe([$admin->id])
        ->and(Activity::query()->where('event', 'assigned')->count())->toBe(2)
        ->and($findings[0]->events()->where('event', FindingEventEnum::Assigned->value)->count())->toBe(1);
});

it('refuses findings of another project and users who cannot handle findings', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    $own = bulkFinding($project, 'vendor/one');
    $foreign = bulkFinding(Project::factory()->create(['slug' => 'other']), 'vendor/two');

    $this->actingAs($admin)
        ->put(route('projects.findings.bulk.assignee', $project), ['ids' => [$own->id, $foreign->id], 'assigned_user_id' => $admin->id])
        ->assertSessionHasErrors('ids');
    $this->actingAs($reader)
        ->put(route('projects.findings.bulk.assignee', $project), ['ids' => [$own->id], 'assigned_user_id' => $reader->id])
        ->assertForbidden();

    expect($own->fresh()->assigned_user_id)->toBeNull();
});
