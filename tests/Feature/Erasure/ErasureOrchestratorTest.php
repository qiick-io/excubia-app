<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeleteUserAction;
use App\Data\ApiKeyData;
use App\Enums\ActivityEventEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\RoleEnum;
use App\Models\ApiKey;
use App\Models\ErasureRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ErasureOrchestratorService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
 * The resumable user erasure (#381): one request per case, every step from the encrypted
 * snapshot, a failed step resumed by excubia:resume-erasures, the case closed without the person.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
});

function erasableUser(): User
{
    $user = User::factory()->create(['email' => 'erase-me@example.test', 'username' => 'erase-me', 'first_name' => 'Erasmus', 'last_name' => 'Gone']);
    app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Erasmus laptop', [ApiKeyScopeEnum::McpRead], null));

    return $user;
}

it('erases a user in every step and closes the case without the person', function () {
    $user = erasableUser();
    $apiKey = ApiKey::query()->sole();
    $originalKeyUuid = $apiKey->uuid;

    app(DeleteUserAction::class)->execute($user);

    $erasureRequest = ErasureRequest::query()->sole();
    $apiKey->refresh();

    expect($erasureRequest->status)->toBe(ErasureRequest::STATUS_COMPLETED)
        ->and($erasureRequest->getRawOriginal('subject'))->toBeNull()
        ->and($erasureRequest->user_id)->toBeNull()
        ->and(collect($erasureRequest->steps)->pluck('status')->unique()->all())->toBe(['done'])
        ->and($apiKey->isRevoked())->toBeTrue()
        ->and($apiKey->uuid)->not->toBe($originalKeyUuid)
        ->and($apiKey->name)->toBe('[erased]')
        ->and(User::withTrashed()->findOrFail($user->id)->trashed())->toBeTrue();

    $audit = Activity::query()->where('event', ActivityEventEnum::ErasureCompleted->value)->sole();
    expect($audit->properties['erasure_request_id'])->toBe($erasureRequest->id)
        ->and(json_encode($audit->properties))->not->toContain('erase-me');
});

it('leaves a failed step for excubia:resume-erasures, which finishes the case', function () {
    $user = erasableUser();
    // The OIDC eraser's table is away: its step fails, the next run finds it back.
    DB::statement('ALTER TABLE user_identities RENAME TO user_identities_away');

    app(DeleteUserAction::class)->execute($user);
    $erasureRequest = ErasureRequest::query()->sole();

    // Locked out at once, even though the erasure stopped.
    expect($erasureRequest->status)->toBe(ErasureRequest::STATUS_FAILED)
        ->and($erasureRequest->last_error_code)->toBe('step_failed:oidc')
        ->and($erasureRequest->steps['api-keys']['status'])->toBe('done')
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->email)->toBe('erase-me@example.test');

    DB::statement('ALTER TABLE user_identities_away RENAME TO user_identities');

    $this->artisan('excubia:resume-erasures', ['--dry-run' => true])->expectsOutputToContain('Would resume 1 erasures')->assertSuccessful();
    expect($erasureRequest->fresh()->status)->toBe(ErasureRequest::STATUS_FAILED);

    $this->artisan('excubia:resume-erasures')->assertSuccessful();

    expect($erasureRequest->fresh()->status)->toBe(ErasureRequest::STATUS_COMPLETED)
        ->and(User::withTrashed()->findOrFail($user->id)->email)->not->toBe('erase-me@example.test');
});

it('marks a snapshot that no longer decrypts and never retries it', function () {
    $user = erasableUser();
    DB::statement('ALTER TABLE user_identities RENAME TO user_identities_away');
    app(DeleteUserAction::class)->execute($user);
    DB::statement('ALTER TABLE user_identities_away RENAME TO user_identities');
    $erasureRequest = ErasureRequest::query()->sole();
    DB::table('erasure_requests')->where('id', $erasureRequest->id)->update(['subject' => 'not-encrypted']);

    $this->artisan('excubia:resume-erasures')->assertFailed();
    expect($erasureRequest->fresh()->last_error_code)->toBe(ErasureRequest::ERROR_SNAPSHOT_UNREADABLE);

    $this->artisan('excubia:resume-erasures')->expectsOutputToContain('Resumed 0 erasures')->assertSuccessful();
});

it('keeps one open case per user', function () {
    $user = erasableUser();

    $first = DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));
    $second = DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));

    expect($second->id)->toBe($first->id)
        ->and(ErasureRequest::query()->count())->toBe(1);
});

it('replaces the person\'s values in the activity log, and keeps the rows', function () {
    $user = erasableUser();
    $user->update(['first_name' => 'Erasmus2']);
    $activityIds = Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->pluck('id');

    app(DeleteUserAction::class)->execute($user);

    foreach (Activity::query()->whereKey($activityIds)->get() as $activity) {
        $payload = json_encode([$activity->properties, $activity->attribute_changes]);

        expect($payload)->not->toContain('erase-me@example.test')
            ->and($payload)->not->toContain('Erasmus');
    }

    expect(Activity::query()->whereKey($activityIds)->count())->toBe($activityIds->count());
});

it('keeps the project names in the activity the person caused', function () {
    $user = erasableUser();
    $this->actingAs($user);
    $project = Project::factory()->create(['name' => 'Shop']);
    $projectActivityIds = Activity::query()->where('subject_type', $project->getMorphClass())->where('subject_id', $project->id)->pluck('id');

    app(DeleteUserAction::class)->execute($user);

    expect($projectActivityIds)->not->toBeEmpty();

    foreach (Activity::query()->whereKey($projectActivityIds)->get() as $activity) {
        expect(json_encode($activity->attribute_changes))->toContain('Shop');
    }
});
