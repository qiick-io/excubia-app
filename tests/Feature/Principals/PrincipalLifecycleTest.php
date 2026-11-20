<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PrincipalTypeEnum;
use App\Enums\RoleEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/**
 * Fleet principals: one `user` principal per user; API keys carry owner_principal_id;
 * deactivating a user stamps principals.deactivated_at.
 */
test('principals and api_keys.owner_principal_id exist', function (): void {
    expect(Schema::hasTable('principals'))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'owner_principal_id'))->toBeTrue();
});

test('creating a user creates a user principal', function (): void {
    $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    $principal = Principal::query()->where('user_id', $user->getKey())->sole();

    expect($principal->type)->toBe(PrincipalTypeEnum::User)
        ->and($principal->organisation_id)->toBeNull()
        ->and($principal->name)->toBe('Ada Lovelace')
        ->and($principal->deactivated_at)->toBeNull()
        ->and($user->principal?->is($principal))->toBeTrue();
});

test('creating an api key writes owner_principal_id from the owner user', function (): void {
    $owner = User::factory()->create();

    $plain = app(CreateApiKeyAction::class)->execute(
        $owner,
        new ApiKeyData('CI', [ApiKeyScopeEnum::McpRead], null),
    );

    $apiKey = ApiKey::query()->sole();
    $principal = $owner->principal()->firstOrFail();

    expect($plain)->not->toBeEmpty()
        ->and($apiKey->owner_id)->toBe($owner->id)
        ->and($apiKey->owner_principal_id)->toBe($principal->id);
});

test('deactivating a user stamps the principal deactivated_at', function (): void {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $target = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $principal = $target->principal()->firstOrFail();

    expect($principal->deactivated_at)->toBeNull();

    $this->actingAs($admin)
        ->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'timezone' => 'UTC',
            'password' => '',
            'roles' => [RoleEnum::Reader->value],
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect($principal->refresh()->deactivated_at)->not->toBeNull();

    $this->actingAs($admin)
        ->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'timezone' => 'UTC',
            'password' => '',
            'roles' => [RoleEnum::Reader->value],
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($principal->refresh()->deactivated_at)->toBeNull();
});
