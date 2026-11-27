<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

it('accepts a password of one character type under each saved policy', function (string $policy, string $password) {
    Http::fake();
    app(SettingsRepositoryService::class)->save(['password_policy' => $policy]);
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertSessionHasNoErrors()->assertRedirect(route('security.edit'));

    expect(Hash::check($password, $user->refresh()->password))->toBeTrue()
        ->and(Password::defaults()->toPasswordRulesString())->not->toContain('required:');
    Http::assertSentCount($policy === 'strong' ? 1 : 0);
})->with([
    'weak with uppercase letters' => ['weak', 'SILVEROAK'],
    'medium with lowercase letters' => ['medium', 'rainonstones'],
    'strong with lowercase letters' => ['strong', 'chestnutmoonflowers'],
    'strong with digits' => ['strong', '5729481360475832'],
    'strong with symbols' => ['strong', '~^-^_...&+*{!}??'],
]);

it('enforces the minimum length for each saved policy', function (string $policy, int $minimum) {
    Http::fake();
    app(SettingsRepositoryService::class)->save(['password_policy' => $policy]);

    expect(Validator::make(['password' => str_repeat('q', $minimum - 1)], ['password' => Password::default()])->fails())->toBeTrue()
        ->and(Validator::make(['password' => str_repeat('q', $minimum)], ['password' => Password::default()])->passes())->toBeTrue();
})->with([
    'weak' => ['weak', 8],
    'medium' => ['medium', 12],
    'strong' => ['strong', 15],
]);
