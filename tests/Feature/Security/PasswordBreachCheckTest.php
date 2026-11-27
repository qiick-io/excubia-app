<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    app(SettingsRepositoryService::class)->save(['password_policy' => 'strong']);
});

it('rejects a breached password after sending only its padded hash range prefix', function () {
    $password = 'copperashpebble';
    $hash = strtoupper(sha1($password));
    $rangeUrl = 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5);
    Http::fake([
        $rangeUrl => Http::response(substr($hash, 5).':42'),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->putJson(route('user-password.update'), [
        'current_password' => 'password',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $rangeUrl
        && $request->method() === 'GET'
        && $request->body() === ''
        && $request->hasHeader('Add-Padding', 'true'));
    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('logs a safe warning and accepts a password when the breach service is unavailable', function (?int $status) {
    Log::spy();
    Http::fake(function (Request $request, array $options) use ($status) {
        expect($options['timeout'])->toBe(3)
            ->and($options['connect_timeout'])->toBe(3)
            ->and($options['allow_redirects'])->toBeFalse();

        return $status === null ? Http::failedConnection()($request) : Http::response('Service unavailable', $status);
    });
    $user = User::factory()->create();
    $password = 'whiterivermossfern';

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertSessionHasNoErrors()->assertRedirect(route('security.edit'));

    Log::shouldHaveReceived('warning')->once()->with('Pwned Passwords lookup unavailable.', ['http_status' => $status]);
    expect(Hash::check($password, $user->refresh()->password))->toBeTrue();
})->with(['connection failure' => [null], 'service failure' => [503]]);
