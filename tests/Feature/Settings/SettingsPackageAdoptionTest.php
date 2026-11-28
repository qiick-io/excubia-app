<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Qiick\Settings\Settings;

/**
 * What adopting qiick/laravel-settings adds (docs/adoption-plan.md §7): the package migration
 * owns `settings` (encryption, revision, metadata), secrets stay out of forEdit()/all(), and
 * undeclared keys are refused at the store.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('uses the package settings table shape', function () {
    expect(Schema::hasColumns('settings', ['encryption', 'revision', 'metadata']))->toBeTrue()
        ->and(Schema::hasTable('settings_write_locks'))->toBeTrue();
});

it('keeps secrets out of all() and forEdit(), and surfaces has_ flags instead', function () {
    $settings = app(SettingsRepositoryService::class);
    $settings->setMany(['notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/SECRET']);

    expect($settings->all())->not->toHaveKey('notifier_slack_webhook_url')
        ->and($settings->forEdit()['has_notifier_slack_webhook_url'])->toBeTrue()
        ->and($settings->forEdit())->not->toHaveKey('notifier_slack_webhook_url')
        ->and(json_encode($settings->forEdit()))->not->toContain('hooks.slack.com');
});

it('refuses an undeclared settings key', function () {
    expect(fn () => app(Settings::class)->set(['not_a_declared_key' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});

it('passes settings:check after adoption', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $this->artisan('settings:check')->assertSuccessful();
});

it('still drops pre-#166 undeclared rule rows through the product forget path', function () {
    Setting::query()->create([
        'scope' => SettingsRepositoryService::SCOPE_INSTANCE,
        'scope_id' => SettingsRepositoryService::SCOPE_ID_INSTANCE,
        'group' => SettingsRepositoryService::GROUP_APP,
        'key' => 'channels.slack.minimum_severity',
        'value' => ['required', 'in:"info","low"'],
    ]);

    app(SettingsRepositoryService::class)->forget(['channels.slack.minimum_severity']);

    expect(Setting::query()->where('key', 'channels.slack.minimum_severity')->exists())->toBeFalse();
});
