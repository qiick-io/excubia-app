<?php

declare(strict_types=1);

use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\GlobalSearchService;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/**
 * @return list<string> The labels of one result group in a search answer
 */
function searchLabels(array $answer, string $type): array
{
    foreach ($answer['groups'] as $group) {
        if ($group['type'] === $type) {
            return array_column($group['items'], 'label');
        }
    }

    return [];
}

it('finds projects by name or slug, linking to the project page', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    Project::factory()->create(['name' => 'Marketing site']);

    $answer = $this->actingAs($admin)->getJson(route('search', ['query' => 'bill']))->assertOk()->json();

    expect(searchLabels($answer, 'projects'))->toBe(['Billing API'])
        ->and($answer['groups'][0]['items'][0]['url'])->toBe(route('projects.show', $project->slug, false));

    $answer = $this->actingAs($admin)->getJson(route('search', ['query' => 'billing-a']))->json();

    expect(searchLabels($answer, 'projects'))->toBe(['Billing API']);
});

it('never returns a project the user cannot see', function () {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    Project::factory()->create(['name' => 'Client open']);
    Project::factory()->create(['name' => 'Client hidden', 'visibility' => ProjectVisibilityEnum::Admins]);

    $answer = $this->actingAs($reader)->getJson(route('search', ['query' => 'client']))->assertOk()->json();

    expect(searchLabels($answer, 'projects'))->toBe(['Client open']);
});

it('returns users and groups only to who may list them', function () {
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin@example.com'])->assignRole(RoleEnum::Admin->value);
    $reader = User::factory()->create(['first_name' => 'Rita', 'last_name' => 'Reader', 'email' => 'rita@example.com'])->assignRole(RoleEnum::Reader->value);
    // Fixed emails: a random one could contain the query and match another user.
    User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'lovelace@example.com']);
    UserGroup::query()->create(['name' => 'Ada team']);
    ProjectGroup::query()->create(['name' => 'Ada clients']);

    $adminAnswer = $this->actingAs($admin)->getJson(route('search', ['query' => 'ada']))->json();

    expect(searchLabels($adminAnswer, 'users'))->toBe(['Ada Admin', 'Ada Lovelace'])
        ->and(searchLabels($adminAnswer, 'groups'))->toBe(['Ada team'])
        ->and(searchLabels($adminAnswer, 'projectGroups'))->toBe(['Ada clients']);

    $readerAnswer = $this->actingAs($reader)->getJson(route('search', ['query' => 'ada']))->json();

    expect(searchLabels($readerAnswer, 'users'))->toBe([])
        ->and(searchLabels($readerAnswer, 'groups'))->toBe([]);
});

it('caps each group', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    Project::factory()->count(GlobalSearchService::LIMIT_PER_GROUP + 2)->sequence(fn ($sequence) => ['name' => "Shop {$sequence->index}"])->create();

    $capped = $this->actingAs($admin)->getJson(route('search', ['query' => 'shop']))->json();

    expect(searchLabels($capped, 'projects'))->toHaveCount(GlobalSearchService::LIMIT_PER_GROUP);
});

it('needs two characters and a signed-in user', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)->getJson(route('search', ['query' => 'a']))->assertUnprocessable();
    auth()->logout();
    $this->getJson(route('search', ['query' => 'billing']))->assertUnauthorized();
});

it('finds recent exception groups of visible projects, linking to their drawer', function () {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $visible = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $hidden = Project::factory()->create(['slug' => 'hidden', 'visibility' => ProjectVisibilityEnum::Admins]);
    $make = fn (Project $project, string $fingerprint, string $lastSeen) => ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat($fingerprint, 64), 'environment' => 'production',
        'class' => 'PaymentException', 'message' => 'Card declined', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => $lastSeen, 'last_seen_at' => $lastSeen, 'status' => 'open',
    ]);
    $recent = $make($visible, 'a', now()->subDay()->toDateTimeString());
    $make($visible, 'b', now()->subDays(20)->toDateTimeString());
    $make($hidden, 'c', now()->toDateTimeString());

    $answer = $this->actingAs($reader)->getJson(route('search', ['query' => 'payment']))->assertOk()->json();
    $exceptions = collect($answer['groups'])->firstWhere('type', 'exceptions');

    expect($exceptions['items'])->toHaveCount(1)
        ->and($exceptions['items'][0]['url'])->toBe(route('projects.view', ['project' => 'shop', 'view' => 'exceptions', 'range' => '14d', 'status' => 'all', 'exception' => $recent->id], false));
});
