<?php

declare(strict_types=1);

use App\Enums\AlertEventEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;

/**
 * The compact event picker of an alert channel (#205): "All events" hides the list, "Selected
 * events" opens it grouped by category, and a group checkbox turns its events off; the saved
 * filter keeps the `event_types` list shape.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('saves the events of a channel from the grouped picker', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    visit(route('project.integrations.edit', absolute: false))
        ->assertDontSee('A finding of the previous report is gone.')
        ->click('#channels-slack--events-selected')
        ->assertSee('A finding of the previous report is gone.')
        ->click('#channels-slack--group-findings')
        ->assertSee('0/5')
        ->click('[data-test="project-integrations-save"]')
        ->wait(0.5)
        ->assertNoJavaScriptErrors();

    $slackEvents = app(SettingsRepositoryService::class)->alertChannelFilter('slack')->eventTypes;
    $webhookEvents = app(SettingsRepositoryService::class)->alertChannelFilter('webhook')->eventTypes;

    expect($slackEvents)->not->toContain('finding.new', 'finding.resolved', 'report.checks_skipped', 'eol.approaching', 'eol.reached')
        ->toContain('health.failed', 'ai.error_rate')
        ->and($webhookEvents)->toEqualCanonicalizing(AlertEventEnum::selectableValues());
});
