<?php

declare(strict_types=1);

use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

/**
 * Bulk actions on the Exceptions tab (#276): status and assignee for the selected groups, each
 * group authorized and logged on its own.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-11 09:00:00');
});

function bulkException(Project $project, string $fingerprint, string $status = 'open'): ExceptionGroup
{
    return ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat($fingerprint, 64), 'environment' => 'production',
        'class' => 'RuntimeException', 'message' => 'Payment failed', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(), 'status' => $status,
    ]);
}

it('resolves the selected groups and logs one entry per changed group', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    $first = bulkException($project, 'a');
    $second = bulkException($project, 'b');
    $alreadyResolved = bulkException($project, 'c', 'resolved');

    $this->actingAs($admin)
        ->patch(route('projects.exceptions.bulk.status', $project), ['ids' => [$first->id, $second->id, $alreadyResolved->id], 'status' => 'resolved'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($first->fresh()->status->value)->toBe('resolved')
        ->and($second->fresh()->resolved_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'status_changed')->pluck('subject_id')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

it('assigns the selected groups to a user who can see the project', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    $groups = [bulkException($project, 'a'), bulkException($project, 'b')];
    $ids = array_map(fn (ExceptionGroup $group): int => $group->id, $groups);

    $this->actingAs($admin)
        ->put(route('projects.exceptions.bulk.assignee', $project), ['ids' => $ids, 'assigned_user_id' => $reader->id])
        ->assertSessionHasNoErrors();

    expect(ExceptionGroup::query()->whereIn('id', $ids)->pluck('assigned_user_id')->unique()->all())->toBe([$reader->id])
        ->and(Activity::query()->where('event', 'assigned')->count())->toBe(2);

    $project->update(['visibility' => ProjectVisibilityEnum::Admins]);

    $this->actingAs($admin)
        ->put(route('projects.exceptions.bulk.assignee', $project), ['ids' => $ids, 'assigned_user_id' => $reader->id])
        ->assertSessionHasErrors('assigned_user_id');
});

it('refuses groups of another project, users without the permission and empty selections', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $project = Project::factory()->create(['slug' => 'shop']);
    $other = Project::factory()->create(['slug' => 'other']);
    $own = bulkException($project, 'a');
    $foreign = bulkException($other, 'b');

    $this->actingAs($admin)
        ->patch(route('projects.exceptions.bulk.status', $project), ['ids' => [$own->id, $foreign->id], 'status' => 'ignored'])
        ->assertSessionHasErrors('ids');
    $this->actingAs($admin)
        ->patch(route('projects.exceptions.bulk.status', $project), ['ids' => [], 'status' => 'ignored'])
        ->assertSessionHasErrors('ids');
    $this->actingAs($reader)
        ->patch(route('projects.exceptions.bulk.status', $project), ['ids' => [$own->id], 'status' => 'ignored'])
        ->assertForbidden();

    expect($own->fresh()->status->value)->toBe('open')
        ->and($foreign->fresh()->status->value)->toBe('open');
});
