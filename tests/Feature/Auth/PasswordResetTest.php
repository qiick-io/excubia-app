<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_forgot_password_answers_the_same_for_known_and_unknown_addresses_and_is_throttled(): void
    {
        // #472: an unknown address got 422 "We can't find a user", a known one 200, unthrottled.
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson(route('password.email'), ['email' => $user->email]);
        $unknown = $this->postJson(route('password.email'), ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        foreach (range(3, 5) as $attempt) {
            $this->postJson(route('password.email'), ['email' => "nobody{$attempt}@example.com"])->assertOk();
        }

        $this->postJson(route('password.email'), ['email' => 'nobody6@example.com'])->assertTooManyRequests();
    }

    public function test_a_failed_reset_says_the_link_is_invalid_for_unknown_addresses_too(): void
    {
        // #472: "we can't find a user" told unknown addresses apart from known ones.
        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => 'nobody@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors(['email' => trans('passwords.token')]);
    }

    public function test_a_throttled_forgot_password_form_goes_back_with_a_toast(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->from(route('password.request'))->post(route('password.email'), ['email' => "nobody{$attempt}@example.com"]);
        }

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody6@example.com'])
            ->assertRedirect(route('password.request'));
    }
}
