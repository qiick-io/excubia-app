<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeleteUserAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\RoleEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * What adopting qiick/laravel-audit adds to Excubia's activity log (docs/adoption-plan.md §6):
 * server-side attribution of every row written under an API key, which a caller cannot forge,
 * a public id on every row, and the erasure of a deleted user from the log.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Route::middleware(AuthenticateApiKeyMiddleware::class.':mcp:read')
        ->post('/__test/audit-under-key', function () {
            activity()->withProperties(['api_key_id' => (string) Str::uuid(), 'note' => 'kept'])->log('written under a key');

            return response()->json(['ok' => true]);
        });
});

it('attributes a row written under an API key to the key, whatever the caller passes', function () {
    $owner = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $plainKey = app(CreateApiKeyAction::class)->execute($owner, new ApiKeyData('Agent', [ApiKeyScopeEnum::McpRead], null));
    $apiKey = ApiKey::query()->sole();

    $this->withToken($plainKey)->postJson('/__test/audit-under-key')->assertOk();

    $row = Activity::query()->where('description', 'written under a key')->sole();

    expect($row->properties['api_key_id'])->toBe($apiKey->uuid)
        ->and($row->properties['api_key_prefix'])->toBe($apiKey->key_prefix)
        ->and($row->properties['api_key_created_by'])->toBe($owner->public_id)
        ->and($row->properties['actor_kind'])->toBe('api_key')
        ->and($row->properties['note'])->toBe('kept')
        ->and($row->causer_id)->toBe((string) $owner->id)
        ->and(Str::isUuid($row->public_id))->toBeTrue();
});

it('drops a caller-supplied api_key_id on a web request', function () {
    $this->actingAs(User::factory()->create());

    activity()->withProperties(['api_key_id' => (string) Str::uuid()])->log('forged');

    expect(Activity::query()->where('description', 'forged')->sole()->properties)->not->toHaveKey('api_key_id');
});

it('erases a deleted user from the activity log, keeping the events', function () {
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $user = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace.hopper@example.com']);
    activity()->causedBy($user)->withProperties(['email' => 'grace.hopper@example.com'])->log('Grace Hopper changed the alert settings');

    app(DeleteUserAction::class)->execute($user);

    $row = Activity::query()->where('event', null)->where('causer_id', (string) $user->id)->sole();

    expect($row->anonymised_at)->not->toBeNull()
        ->and($row->description)->not->toContain('Grace')->not->toContain('Hopper')
        ->and(json_encode($row->properties))->not->toContain('grace.hopper@example.com');
});
