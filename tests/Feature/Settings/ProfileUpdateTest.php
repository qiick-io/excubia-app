<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_public_id_and_timezone_without_oidc_sub(): void
    {
        expect(Schema::hasColumn('users', 'public_id'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'timezone'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'oidc_sub'))->toBeFalse();

        $user = User::factory()->create();

        expect($user->public_id)->not->toBeEmpty()
            ->and(Str::isUuid($user->public_id))->toBeTrue()
            ->and($user->timezone)->toBe('UTC');
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/profile')
                ->has('timezones')
                ->where('timezones', timezone_identifiers_list())
                ->where('auth.user.timezone', 'UTC'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'test@example.com',
                'locale' => 'it',
                'timezone' => 'Europe/Rome',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertCookie('locale', 'it', encrypted: false);

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('it', $user->locale);
        $this->assertSame('Europe/Rome', $user->timezone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_update_rejects_a_non_iana_timezone(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => $user->email,
                'locale' => 'en',
                'timezone' => 'Not/A_Zone',
            ])
            ->assertSessionHasErrors('timezone')
            ->assertRedirect(route('profile.edit'));

        expect($user->refresh()->timezone)->toBe('UTC');
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => $user->email,
                'locale' => 'en',
                'timezone' => 'UTC',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();
        $principal = $user->principal()->firstOrFail();
        $apiKey = ApiKey::factory()->create(['owner_id' => $user->id]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();

        expect($user->fresh())->not->toBeNull()
            ->and($user->fresh()->trashed())->toBeTrue()
            ->and($apiKey->fresh()->isRevoked())->toBeTrue()
            ->and($principal->fresh()->user_id)->toBeNull()
            ->and($principal->fresh()->deactivated_at)->not->toBeNull();
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
