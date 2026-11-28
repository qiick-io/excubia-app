<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeleteUserAction;
use App\Data\ApiKeyData;
use App\Enums\ActivityEventEnum;
use App\Enums\AlertEventEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use App\Models\ApiKey;
use App\Models\ErasureRequest;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\AlertDispatcherService;
use App\Services\ApiKeyErasureRepositoryService;
use App\Services\ApiKeyRepositoryService;
use App\Services\ErasureOrchestratorService;
use App\Services\ReportAlertsService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * The resumable user erasure (#381): one request per case, every step from the encrypted
 * snapshot, a failed step resumed by excubia:resume-erasures, the case closed without the person.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
});

/**
 * Makes every statement on `user_identities` fail while the flag is set, on any engine (no DDL,
 * which would end the test's transaction on MySQL).
 */
function failUserIdentitiesWhile(ArrayObject $flag): void
{
    DB::connection()->beforeExecuting(function (string $query) use ($flag): void {
        if ($flag['down'] && str_contains($query, 'user_identities')) {
            throw new RuntimeException('user_identities unavailable');
        }
    });
}

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

it('runs the qiick/laravel-audit eraser as its own step', function () {
    $user = erasableUser();
    $this->actingAs($user);
    $project = Project::factory()->create(['name' => 'Shop']);
    $causedIds = Activity::query()->where('causer_type', $user->getMorphClass())->where('causer_id', $user->id)->pluck('id');

    app(DeleteUserAction::class)->execute($user);

    expect(array_keys(ErasureRequest::query()->sole()->steps))->toContain('audit')
        ->and(ErasureRequest::query()->sole()->steps['audit']['status'])->toBe('done')
        ->and($causedIds)->not->toBeEmpty()
        ->and(Activity::query()->whereKey($causedIds)->whereNull('anonymised_at')->count())->toBe(0)
        ->and(json_encode(Activity::query()->where('subject_id', $project->id)->where('subject_type', $project->getMorphClass())->value('attribute_changes')))->toContain('Shop');
});

it('keeps the project name when the person only acted through their API key on another subject', function () {
    $user = erasableUser();
    $apiKey = ApiKey::query()->sole();
    $otherCauser = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Shop']);

    Activity::query()->create([
        'public_id' => (string) Str::uuid7(),
        'log_name' => 'default',
        'description' => 'Project updated',
        'event' => 'updated',
        'subject_type' => $project->getMorphClass(),
        'subject_id' => (string) $project->id,
        'causer_type' => $otherCauser->getMorphClass(),
        'causer_id' => (string) $otherCauser->id,
        'properties' => [
            'api_key_id' => $apiKey->uuid,
            'ip' => '203.0.113.9',
        ],
        'attribute_changes' => [
            'attributes' => ['name' => 'Shop'],
            'old' => ['name' => 'Store'],
        ],
    ]);

    app(DeleteUserAction::class)->execute($user);

    $credentialLinked = Activity::query()->where('description', 'Project updated')->sole();
    $attributeChanges = json_encode($credentialLinked->attribute_changes);

    expect($credentialLinked->anonymised_at)->not->toBeNull()
        ->and($attributeChanges)->toContain('Shop')
        ->and($attributeChanges)->toContain('Store')
        ->and($attributeChanges)->not->toContain('"name":"erased"')
        ->and(json_encode($credentialLinked->properties))->not->toContain('203.0.113.9');
});

it('leaves a failed step for excubia:resume-erasures, which finishes the case', function () {
    $user = erasableUser();
    // The OIDC eraser's table refuses every statement: its step fails; the next run succeeds.
    $isOidcStoreDown = new ArrayObject(['down' => true]);
    failUserIdentitiesWhile($isOidcStoreDown);

    app(DeleteUserAction::class)->execute($user);
    $erasureRequest = ErasureRequest::query()->sole();

    // Locked out at once, even though the erasure stopped.
    expect($erasureRequest->status)->toBe(ErasureRequest::STATUS_FAILED)
        ->and($erasureRequest->last_error_code)->toBe('step_failed:oidc')
        ->and($erasureRequest->steps['api-keys']['status'])->toBe('done')
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->email)->toBe('erase-me@example.test');

    $isOidcStoreDown['down'] = false;

    $this->artisan('excubia:resume-erasures', ['--dry-run' => true])->expectsOutputToContain('Would resume 1 erasures')->assertSuccessful();
    expect($erasureRequest->fresh()->status)->toBe(ErasureRequest::STATUS_FAILED);

    $this->artisan('excubia:resume-erasures')->assertSuccessful();

    expect($erasureRequest->fresh()->status)->toBe(ErasureRequest::STATUS_COMPLETED)
        ->and(User::withTrashed()->findOrFail($user->id)->email)->not->toBe('erase-me@example.test');
});

it('marks a snapshot that no longer decrypts and never retries it', function () {
    $user = erasableUser();
    $isOidcStoreDown = new ArrayObject(['down' => true]);
    failUserIdentitiesWhile($isOidcStoreDown);
    app(DeleteUserAction::class)->execute($user);
    $isOidcStoreDown['down'] = false;
    $erasureRequest = ErasureRequest::query()->sole();
    DB::table('erasure_requests')->where('id', $erasureRequest->id)->update(['subject' => 'not-encrypted']);

    $this->artisan('excubia:resume-erasures')->assertFailed();
    expect($erasureRequest->fresh()->last_error_code)->toBe(ErasureRequest::ERROR_SNAPSHOT_UNREADABLE);

    // Not retried, but listed for an admin on every run.
    $this->artisan('excubia:resume-erasures')
        ->expectsOutputToContain('Resumed 0 erasures')
        ->expectsOutputToContain('needs an admin')
        ->assertFailed();
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

it('scrubs failed logins, the request data of the rows they caused and the alert history that names them', function () {
    $user = erasableUser();
    $project = Project::factory()->create();
    $this->post('/login', ['email' => 'erase-me@example.test', 'password' => 'wrong-password']);
    $failedLogin = Activity::query()->where('event', 'failed')->latest('id')->firstOrFail();
    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(route('dashboard'));
    activity()->causedBy($user)->event('assigned')->log('Assigned');
    $causedRow = Activity::query()->where('event', 'assigned')->sole();
    $alertLog = AlertLog::query()->create([
        'uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'event' => 'finding.new', 'severity' => 'high',
        'summary' => 'vendor/package: CVE (assigned to Erasmus Old)', 'subject_public_id' => $user->public_id, 'status' => AlertLog::QUEUED, 'deliveries' => [],
    ]);
    // Someone else's alert whose text happens to hold the same words: never touched.
    $otherAlertLog = AlertLog::query()->create([
        'uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'event' => 'finding.new', 'severity' => 'high',
        'summary' => 'Erasmus Old% (assigned to Someone)', 'status' => AlertLog::QUEUED, 'deliveries' => [],
    ]);
    $reader = User::factory()->create();
    $reader->notifyNow(new AlertNotification(['id' => $alertLog->uuid, 'event' => 'finding.new', 'severity' => 'high', 'data' => ['assignee' => 'Erasmus Old']], 'vendor/package (assigned to Erasmus Old)'));

    app(DeleteUserAction::class)->execute($user);

    expect($failedLogin->fresh()->properties->get('identifier'))->toBe('[erased]')
        ->and($causedRow->fresh()->properties->get('ip'))->toBe('[erased]')
        ->and($causedRow->fresh()->properties->get('user_agent'))->toBe('[erased]')
        ->and($alertLog->fresh()->summary)->toBe('vendor/package: CVE (assigned to [erased])')
        ->and($alertLog->fresh()->subject_public_id)->toBeNull()
        ->and($otherAlertLog->fresh()->summary)->toBe('Erasmus Old% (assigned to Someone)')
        ->and(json_encode($reader->notifications()->sole()->data))->not->toContain('Erasmus Old')
        ->and(User::withTrashed()->findOrFail($user->id)->locale)->toBe('en');
});

it('lets only the executor holding a case advance or close it', function () {
    $user = erasableUser();
    $erasureRequest = DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));
    // Another executor holds it under a live lease.
    $erasureRequest->forceFill(['status' => ErasureRequest::STATUS_RUNNING, 'claim_token' => 'other-executor', 'claimed_until' => now()->addMinutes(10)])->save();

    expect(app(ErasureOrchestratorService::class)->run($erasureRequest->fresh()))->toBeTrue();

    $stored = $erasureRequest->fresh();
    expect($stored->status)->toBe(ErasureRequest::STATUS_RUNNING)
        ->and($stored->claim_token)->toBe('other-executor')
        ->and(collect($stored->steps)->pluck('status')->unique()->all())->toBe(['pending']);

    // Its lease runs out (a crashed executor): the case is taken over and closed once.
    $erasureRequest->forceFill(['claimed_until' => now()->subMinute()])->save();
    app(ErasureOrchestratorService::class)->run($erasureRequest->fresh());
    app(ErasureOrchestratorService::class)->run($erasureRequest->fresh());

    expect($erasureRequest->fresh()->status)->toBe(ErasureRequest::STATUS_COMPLETED)
        ->and(Activity::query()->where('event', ActivityEventEnum::ErasureCompleted->value)->count())->toBe(1);
});

it('refuses a profile update and a new key that were authorised before the erasure began', function () {
    $user = erasableUser();
    DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));

    $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Back', 'email' => 'back@example.test', 'locale' => 'en', 'timezone' => 'UTC'])->assertForbidden();

    expect(fn () => app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Late key', [ApiKeyScopeEnum::McpRead], null)))
        ->toThrow(AuthorizationException::class);
    expect(ApiKey::query()->count())->toBe(1);
});

it('anonymises a key its owner named [erased], and a second run changes nothing', function () {
    $user = erasableUser();
    ApiKey::query()->sole()->forceFill(['name' => '[erased]', 'last_used_ip' => '198.51.100.7'])->save();
    $originalUuid = ApiKey::query()->sole()->uuid;

    app(DeleteUserAction::class)->execute($user);
    $apiKey = ApiKey::query()->sole();

    expect($apiKey->uuid)->not->toBe($originalUuid)
        ->and($apiKey->last_used_ip)->toBeNull()
        ->and($apiKey->isRevoked())->toBeTrue()
        ->and(app(ApiKeyErasureRepositoryService::class)->eraseForUser((string) $user->id, $user->public_id, 'anonymise', [], [], false)['anonymised'])->toBe(0);
});

it('deletes only the reset token of the person, never one that matches their name', function () {
    $user = erasableUser();
    DB::table('password_reset_tokens')->insert([
        ['email' => 'erase-me@example.test', 'token' => 'a', 'created_at' => now()],
        ['email' => $user->name, 'token' => 'b', 'created_at' => now()],
    ]);

    app(DeleteUserAction::class)->execute($user);

    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe([$user->name]);
});

it('leaves an unreadable case untouched in a dry run', function () {
    $user = erasableUser();
    $erasureRequest = DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));
    DB::table('erasure_requests')->where('id', $erasureRequest->id)->update(['subject' => 'not-encrypted']);

    $this->artisan('excubia:resume-erasures', ['--dry-run' => true])->assertFailed();

    expect($erasureRequest->fresh()->status)->toBe(ErasureRequest::STATUS_PENDING)
        ->and($erasureRequest->fresh()->last_error_code)->toBeNull();
});

it('writes the completion entry without a causer or request data, and no logout row after a self-deletion', function () {
    $user = erasableUser();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    $audit = Activity::query()->where('event', ActivityEventEnum::ErasureCompleted->value)->sole();
    expect($audit->causer_id)->toBeNull()
        ->and($audit->properties->has('ip'))->toBeFalse()
        ->and(Activity::query()->where('event', 'logout')->where('causer_id', $user->id)->exists())->toBeFalse();
});

it('does not leave a deleted activity row with the request IP after self-erasure', function () {
    $user = erasableUser();
    $requestIp = '203.0.113.50';

    $this->withServerVariables(['REMOTE_ADDR' => $requestIp])
        ->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(Activity::query()->where('event', 'deleted')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('properties', 'like', '%'.$requestIp.'%')->count())->toBe(0);
});

it('redacts a person erased while their alert waited in the queue', function () {
    $user = erasableUser();
    $reader = User::factory()->create();
    $job = new DeliverAlertJob('app:'.$reader->id, ['id' => (string) Str::uuid(), 'event' => 'finding.new', 'severity' => 'high', 'data' => (object) ['assignee' => $user->name]], 'pkg (assigned to '.$user->name.')', [], [$user->public_id => $user->name]);
    $name = $user->name;

    app(DeleteUserAction::class)->execute($user);
    app()->call([$job, 'handle']);

    expect(json_encode($reader->notifications()->sole()->data))->not->toContain($name)->toContain('[erased]');
});

it('lets an executor that lost the case neither run a step nor write', function () {
    $user = erasableUser();
    DB::table('user_identities')->insert(['public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'issuer' => 'https://idp.example.test', 'subject' => 'sub-1', 'created_at' => now(), 'updated_at' => now()]);
    $erasureRequest = DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));
    // The lease of "suspended" ran out and "successor" took the case over.
    $erasureRequest->forceFill(['status' => ErasureRequest::STATUS_RUNNING, 'claim_token' => 'successor', 'claimed_until' => now()->addMinutes(10)])->save();
    $runFencedStep = new ReflectionMethod(ErasureOrchestratorService::class, 'runFencedStep');

    $isHeld = $runFencedStep->invoke(app(ErasureOrchestratorService::class), $erasureRequest, 'oidc', $erasureRequest->readSubject(), 'suspended');

    expect($isHeld)->toBeFalse()
        ->and(DB::table('user_identities')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($erasureRequest->fresh()->steps['oidc']['status'])->toBe('pending');
});

it('never writes the usage of a revoked key back', function () {
    $user = erasableUser();
    $apiKey = ApiKey::query()->sole();
    app(DeleteUserAction::class)->execute($user);
    $apiKey->refresh();

    app(ApiKeyRepositoryService::class)->recordUsage($apiKey, '198.51.100.9', 'late-client', new DateTimeImmutable);

    expect($apiKey->fresh()->last_used_ip)->toBeNull()
        ->and($apiKey->fresh()->last_used_user_agent)->toBeNull();
});

it('cuts an alert summary, never the assignee name, in the history and the notification', function () {
    $name = 'Erasmus Gone';
    $summary = ReportAlertsService::withAssignee(str_repeat('x', 700), $name, ReportAlertsService::SUMMARY_LENGTH);
    $notification = (new AlertNotification(['id' => (string) Str::uuid(), 'event' => 'finding.new', 'severity' => 'high', 'data' => (object) ['assignee' => $name]], $summary))->toArray(new stdClass);

    expect(mb_strlen($summary))->toBeLessThanOrEqual(ReportAlertsService::SUMMARY_LENGTH)
        ->and($summary)->toEndWith(' (assigned to '.$name.')')
        ->and($notification['body'])->toEndWith(' (assigned to '.$name.')')
        ->and(mb_strlen($notification['body']))->toBeLessThanOrEqual(300);
});

it('stores no name for an alert whose assignee was erased before it was dispatched', function () {
    $user = erasableUser();
    $project = Project::factory()->create();
    $name = $user->name;
    app(DeleteUserAction::class)->execute($user);

    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::FindingNew, $project, 'pkg (assigned to '.$name.')', ['assignee' => $name], subject: 'pkg', subjectPublicId: $user->public_id);
    $alertLog = AlertLog::query()->latest('id')->firstOrFail();

    expect($alertLog->summary)->toBe('pkg (assigned to [erased])')
        ->and($alertLog->subject_public_id)->toBeNull();
});

it('repeats a step whose commit was recorded but not its after-commit work', function () {
    $user = erasableUser();
    $erasureRequest = DB::transaction(fn () => app(ErasureOrchestratorService::class)->begin($user));
    // As after a crash between the step's commit and its cache cleanup.
    $erasureRequest->forceFill(['steps' => [...$erasureRequest->steps, 'api-keys' => ['status' => 'committed', 'changed' => 1]]])->save();

    app(ErasureOrchestratorService::class)->run($erasureRequest->fresh());

    $stored = $erasureRequest->fresh();
    expect($stored->status)->toBe(ErasureRequest::STATUS_COMPLETED)
        ->and(ApiKey::query()->sole()->isRevoked())->toBeTrue();
});
