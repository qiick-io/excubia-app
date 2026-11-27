<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

it('rejects Excubia terms when changing a local password under every policy', function (string $policy, string $password) {
    Http::fake();
    app(SettingsRepositoryService::class)->save(['password_policy' => $policy]);
    $user = User::factory()->create();

    $this->actingAs($user)->putJson(route('user-password.update'), [
        'current_password' => 'password',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('password')
        ->assertJsonPath('errors.password.0', __('validation.password.context_specific'));

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
})->with([
    'weak and a role name' => ['weak', 'rEaDeR'],
    'medium and a translated role name' => ['medium', 'LeTtore2026'],
    'strong and a product name variant' => ['strong', 'ExcuBia_APP2026'],
]);

it('uses the current app name and operator-configured deployment terms', function () {
    config(['excubia.password_denylist' => 'Example Organisation, partner-status']);
    $settings = app(SettingsRepositoryService::class);
    $settings->save(['password_policy' => 'weak', 'app_name' => 'München Map']);

    $isPasswordAllowed = fn (string $password): bool => Validator::make(
        ['password' => $password],
        ['password' => ['required', Password::defaults()]],
    )->passes();

    expect($isPasswordAllowed('MÜNCHEN-Map2026'))->toBeFalse()
        ->and($isPasswordAllowed('example_organisation2026'))->toBeFalse()
        ->and($isPasswordAllowed('PartnerStatus2026'))->toBeFalse()
        ->and($isPasswordAllowed('2026partnerstatusmoonstone'))->toBeTrue();

    $settings->save(['app_name' => 'Office Watch']);
    config(['excubia.password_denylist' => 'customer-status']);

    expect($isPasswordAllowed('MÜNCHEN-Map2026'))->toBeTrue()
        ->and($isPasswordAllowed('office_watch2026'))->toBeFalse()
        ->and($isPasswordAllowed('PartnerStatus2026'))->toBeTrue()
        ->and($isPasswordAllowed('CustomerStatus2026'))->toBeFalse();
});
