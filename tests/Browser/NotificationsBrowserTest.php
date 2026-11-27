<?php

declare(strict_types=1);

use App\Enums\AlertEventEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\User;
use App\Services\AlertDispatcherService;
use Database\Seeders\PermissionSeeder;

/**
 * In-app alerts (#296): the bell in the sidebar footer, right above the user menu (#344), shows the
 * unread count, opens the drawer on the right, and a notification leads to the finding and is
 * marked read, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('opens the bell, follows a notification to the finding and marks it read', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    // The queue runs synchronously in tests: the notification is stored at once.
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::FindingNew, $project, 'laravel/framework: CVE-2026-0001', [
        'checkId' => 'composer-audit', 'subject' => 'laravel/framework', 'advisoryId' => 'CVE-2026-0001',
    ], SeverityEnum::High, 'composer-audit|laravel/framework|CVE-2026-0001');

    $path = route('dashboard', absolute: false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertPresent('[data-test="notifications-bell"][aria-label="Notifications, 1 unread"]')
        ->click('[data-test="notifications-bell"]')
        ->assertSee('Mark all as read')
        ->assertSee('finding.new in Billing API')
        ->assertSee('laravel/framework: CVE-2026-0001')
        ->assertPresent('[data-test="notifications-item"][data-unread]')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="notifications-item"] a')
        ->wait(1)
        ->assertPathIs(route('projects.view', ['project' => 'billing-api', 'view' => 'findings'], false))
        ->assertQueryStringHas('finding', 'composer-audit|laravel/framework|CVE-2026-0001')
        ->assertNoJavaScriptErrors();

    // The read request runs alongside the navigation (keepalive), so it may land just after it.
    $deadline = microtime(true) + 5;

    while ($admin->unreadNotifications()->count() > 0 && microtime(true) < $deadline) {
        usleep(100_000);
    }

    expect($admin->unreadNotifications()->count())->toBe(0);

    // The next page reads the new count: the badge is gone without broadcasting.
    $page->navigate(route('dashboard', absolute: false))
        ->assertPresent('[data-test="notifications-bell"][aria-label="Notifications"]')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

it('keeps the bell in the sidebar footer above the user menu, also in the icon-only sidebar', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::FindingNew, $project, 'laravel/framework: CVE-2026-0001', [
        'checkId' => 'composer-audit', 'subject' => 'laravel/framework', 'advisoryId' => 'CVE-2026-0001',
    ], SeverityEnum::High, 'composer-audit|laravel/framework|CVE-2026-0001');

    // The bell is in the footer, not the header, and comes right before the user menu.
    $bellIsInFooterBeforeUserMenu = <<<'JS'
        () => {
            const bell = document.querySelector('[data-test="notifications-bell"]');
            const userMenu = document.querySelector('[data-sidebar="footer"] [data-test="sidebar-menu-button"]');

            return bell?.closest('[data-sidebar="footer"]') !== null
                && bell?.closest('[data-sidebar="header"]') === null
                && userMenu !== null
                && (bell.compareDocumentPosition(userMenu) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
        }
        JS;

    $path = route('dashboard', absolute: false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->resize(1440, 900)
        ->assertScript($bellIsInFooterBeforeUserMenu, true)
        ->keys('#main-content', 'ControlOrMeta+b')
        ->wait(0.5)
        ->assertPresent('[data-state="collapsed"] [data-sidebar="footer"] [data-test="notifications-bell"][aria-label="Notifications, 1 unread"]')
        ->assertScript($bellIsInFooterBeforeUserMenu, true);

    // The keyboard opens the drawer from the icon-only bell, and Escape closes it.
    $page->assertScript("() => { document.querySelector('[data-test=\"notifications-bell\"]').focus(); return true; }", true)
        ->keys('[data-test="notifications-bell"]', 'Enter')
        ->assertSee('Mark all as read')
        ->wait(0.5)
        ->keys('[role="dialog"]', 'Escape')
        ->wait(1)
        ->assertDontSee('Mark all as read')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
