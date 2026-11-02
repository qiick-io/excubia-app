<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\ThemePresetEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Project settings pages copied from externa-core (General, Localization, Authentication &
 * security, Appearance, Sidebar, Performance), all behind `settings.manage`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function projectSettingsManager(): User
{
    return User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
}

function generalSettingsPayload(array $overrides = []): array
{
    return [
        'app_name' => 'Acme Health',
        'report_bug_url' => 'https://example.com/bugs/new',
        'reports_kept_per_project' => 10,
        'stale_after_hours' => 12,
        ...$overrides,
    ];
}

function securitySettingsPayload(array $overrides = []): array
{
    return [
        'password_policy' => 'medium',
        'login_max_attempts' => 3,
        'two_factor_required' => '0',
        'oidc_enabled' => '0',
        'oidc_jit_provisioning' => '0',
        ...$overrides,
    ];
}

dataset('project settings pages', [
    'general' => ['project.edit', 'settings/project'],
    'localization' => ['project.localization.edit', 'settings/project/localization'],
    'authentication & security' => ['project.security.edit', 'settings/project/security'],
    'appearance' => ['appearance.edit', 'settings/appearance'],
    'sidebar' => ['appearance.sidebar.edit', 'settings/appearance/sidebar'],
    'performance' => ['performance.edit', 'settings/performance'],
]);

dataset('project settings writes', [
    'general' => ['put', 'project.update'],
    'localization' => ['put', 'project.localization.update'],
    'authentication & security' => ['put', 'project.security.update'],
    'appearance' => ['put', 'appearance.update'],
    'sidebar' => ['put', 'appearance.sidebar.update'],
    'permission cache flush' => ['post', 'performance.flush-spatie-permissions'],
]);

it('shows every project settings page to settings.manage', function (string $route, string $component) {
    $this->actingAs(projectSettingsManager())->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('project settings pages');

it('keeps every project settings page behind settings.manage', function (string $route, string $component, RoleEnum $role) {
    $this->actingAs(User::factory()->create()->assignRole($role->value))
        ->get(route($route))
        ->assertForbidden();
})->with('project settings pages')->with([RoleEnum::Admin, RoleEnum::Reader]);

it('keeps every project settings write behind settings.manage', function (string $method, string $route, RoleEnum $role) {
    $this->actingAs(User::factory()->create()->assignRole($role->value))
        ->{$method}(route($route), [...generalSettingsPayload(), ...securitySettingsPayload()])
        ->assertForbidden();
})->with('project settings writes')->with([RoleEnum::Admin, RoleEnum::Reader]);

it('sends guests to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('project settings pages');

it('shows defaults from config until a value is saved', function () {
    config(['excubia.reports_kept_per_project' => 30, 'excubia.stale_after_hours' => 36]);
    $manager = projectSettingsManager();

    $this->actingAs($manager)->get(route('project.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.app_name', null)
            ->where('project.report_bug_url', null)
            ->where('project.reports_kept_per_project', 30)
            ->where('project.stale_after_hours', 36));

    $this->actingAs($manager)->get(route('project.security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.login_max_attempts', 5)
            ->where('project.two_factor_required', false)
            ->where('project.oidc_enabled', false)
            ->where('project.has_oidc_client_secret', false)
            ->where('passwordPolicies', SettingsRepositoryService::PASSWORD_POLICIES));
});

it('saves the general settings and the app uses them', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.update'), generalSettingsPayload())
        ->assertRedirect(route('project.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $settings = app(SettingsRepositoryService::class);

    expect($settings->appName())->toBe('Acme Health')
        ->and($settings->reportBugUrl())->toBe('https://example.com/bugs/new')
        ->and($settings->reportsKeptPerProject())->toBe(10)
        ->and($settings->staleAfterHours())->toBe(12);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('name', 'Acme Health')
        ->where('projectSettings.reportBugUrl', 'https://example.com/bugs/new'));
});

it('shares no report bug URL until one is saved, so the settings nav uses the issue form', function () {
    $this->actingAs(projectSettingsManager())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('projectSettings.reportBugUrl', null));

    expect(base_path('.github/ISSUE_TEMPLATE/bug_report.yml'))->toBeFile();
});

it('validates the general settings', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.update'), generalSettingsPayload([
            'app_name' => str_repeat('a', 101),
            'report_bug_url' => 'not a url',
            'reports_kept_per_project' => 'many',
            'stale_after_hours' => 9999,
        ]))
        ->assertSessionHasErrors(['app_name', 'report_bug_url', 'reports_kept_per_project', 'stale_after_hours']);
});

it('saves the authentication and security settings and the app uses them', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.security.update'), securitySettingsPayload())
        ->assertRedirect(route('project.security.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $settings = app(SettingsRepositoryService::class);

    expect($settings->passwordPolicy())->toBe('medium')
        ->and($settings->loginMaxAttempts())->toBe(3)
        ->and($settings->isTwoFactorRequired())->toBeFalse();

    expect(validator(['password' => 'alllowercase'], ['password' => Password::default()])->fails())->toBeTrue()
        ->and(validator(['password' => 'Mixed1Case'], ['password' => Password::default()])->passes())->toBeTrue();
});

it('validates the authentication and security settings', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.security.update'), securitySettingsPayload([
            'password_policy' => 'none',
            'login_max_attempts' => 0,
            'two_factor_required' => 'maybe',
        ]))
        ->assertSessionHasErrors(['password_policy', 'login_max_attempts', 'two_factor_required']);
});

it('keeps the stored client secret when the field is left empty', function () {
    $manager = projectSettingsManager();
    $sso = [
        'oidc_enabled' => '1',
        'oidc_issuer' => 'https://idp.example.com/realms/excubia',
        'oidc_client_id' => 'excubia-app',
    ];

    $this->actingAs($manager)
        ->put(route('project.security.update'), securitySettingsPayload([...$sso, 'oidc_client_secret' => 'first-secret']))
        ->assertSessionHasNoErrors();

    $this->actingAs($manager)
        ->put(route('project.security.update'), securitySettingsPayload([...$sso, 'oidc_client_secret' => '']))
        ->assertSessionHasNoErrors();

    expect(app(SettingsRepositoryService::class)->oidcClientSecret())->toBe('first-secret')
        ->and(app(SettingsRepositoryService::class)->isOidcLoginAvailable())->toBeTrue();
});

it('uses the saved login attempts for the login rate limit', function () {
    app(SettingsRepositoryService::class)->setMany(['login_max_attempts' => 2]);
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 2);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();
});

it('sends users without 2FA to their security page when 2FA is required', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.security.update'), securitySettingsPayload(['two_factor_required' => '1']))
        ->assertRedirect(route('project.security.edit'));

    expect(app(SettingsRepositoryService::class)->isTwoFactorRequired())->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));

    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('saves the default language and uses it after the user and cookie choice', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.localization.update'), ['default_language' => 'de'])
        ->assertRedirect(route('project.localization.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    auth()->logout();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
    $this->withUnencryptedCookie('locale', 'it')->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));

    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('validates the default language', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('project.localization.update'), ['default_language' => 'fr'])
        ->assertSessionHasErrors('default_language');
});

it('saves the default appearance and uses it when there is no appearance cookie', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('appearance.update'), ['default_appearance' => 'dark'])
        ->assertRedirect(route('appearance.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(app(SettingsRepositoryService::class)->defaultAppearance())->toBe('dark');

    auth()->logout();

    $this->get(route('login'))->assertSee("const appearance = 'dark';", false);
    $this->withUnencryptedCookie('appearance', 'light')->get(route('login'))
        ->assertSee("const appearance = 'light';", false);
});

it('validates the default appearance', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('appearance.update'), ['default_appearance' => 'sepia'])
        ->assertSessionHasErrors('default_appearance');
});

it('shares the default sidebar modules with every page', function () {
    $this->actingAs(projectSettingsManager())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('projectSettings.sidebarModules', [
            ['id' => 'dashboard', 'enabled' => true, 'locked' => true],
            ['id' => 'activity', 'enabled' => true, 'locked' => false],
            ['id' => 'users', 'enabled' => true, 'locked' => false],
            ['id' => 'groups', 'enabled' => true, 'locked' => false],
            ['id' => 'settings', 'enabled' => true, 'locked' => true],
        ]));
});

it('saves the sidebar order and visibility, keeping locked modules on', function () {
    $manager = projectSettingsManager();

    $this->actingAs($manager)
        ->put(route('appearance.sidebar.update'), ['sidebar_modules' => json_encode([
            ['id' => 'groups', 'enabled' => true, 'locked' => false],
            ['id' => 'settings', 'enabled' => false, 'locked' => false],
            ['id' => 'users', 'enabled' => false, 'locked' => false],
            ['id' => 'dashboard', 'enabled' => false, 'locked' => true],
        ])])
        ->assertRedirect(route('appearance.sidebar.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $expected = [
        ['id' => 'groups', 'enabled' => true, 'locked' => false],
        ['id' => 'settings', 'enabled' => true, 'locked' => true],
        ['id' => 'users', 'enabled' => false, 'locked' => false],
        ['id' => 'dashboard', 'enabled' => true, 'locked' => true],
        ['id' => 'activity', 'enabled' => true, 'locked' => false],
    ];

    $this->actingAs($manager)->get(route('appearance.sidebar.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('project.sidebar_modules', $expected));

    $this->actingAs($manager)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('projectSettings.sidebarModules', $expected));
});

it('validates the sidebar modules', function (array $modules) {
    $this->actingAs(projectSettingsManager())
        ->put(route('appearance.sidebar.update'), ['sidebar_modules' => json_encode($modules)])
        ->assertSessionHasErrors();
})->with([
    'unknown module' => [[['id' => 'chat', 'enabled' => true]]],
    'duplicate module' => [[['id' => 'users', 'enabled' => true], ['id' => 'users', 'enabled' => false]]],
    'missing enabled flag' => [[['id' => 'users']]],
    'empty list' => [[]],
]);

it('inserts modules missing from the stored order next to their default neighbour', function () {
    app(SettingsRepositoryService::class)->setMany(['sidebar_modules' => [
        ['id' => 'settings', 'enabled' => true],
        ['id' => 'users', 'enabled' => false],
        ['id' => 'removed-module', 'enabled' => true],
    ]]);

    expect(app(SettingsRepositoryService::class)->sidebarModules())->toBe([
        ['id' => 'dashboard', 'enabled' => true, 'locked' => true],
        ['id' => 'activity', 'enabled' => true, 'locked' => false],
        ['id' => 'settings', 'enabled' => true, 'locked' => true],
        ['id' => 'users', 'enabled' => false, 'locked' => false],
        ['id' => 'groups', 'enabled' => true, 'locked' => false],
    ]);
});

it('shows the runtime and bootstrap cache status', function () {
    config(['cache.default' => 'array', 'queue.default' => 'sync']);

    $this->actingAs(projectSettingsManager())->get(route('performance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('status.cacheStore', 'array')
            ->where('status.queueConnection', 'sync')
            ->where('status.appEnv', 'testing')
            ->has('status.appDebug')
            ->has('bootstrap', 4));
});

it('flushes the permission cache', function () {
    $manager = projectSettingsManager();
    $cacheKey = (string) config('permission.cache.key');

    $this->actingAs($manager)->get(route('performance.edit'))->assertOk();
    expect(Cache::has($cacheKey))->toBeTrue();

    $this->actingAs($manager)
        ->post(route('performance.flush-spatie-permissions'))
        ->assertRedirect(route('performance.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('offers the built-in theme presets on the appearance page', function () {
    $this->actingAs(projectSettingsManager())->get(route('appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance.theme_preset', 'teal')
            ->where('appearance.project_color', null)
            ->where('appearance.brand_passes_aa', true)
            ->has('appearance.theme_presets', count(ThemePresetEnum::cases()))
            ->where('appearance.theme_presets.1.slug', 'indigo'));
});

it('saves the theme preset and brand colours', function () {
    $manager = projectSettingsManager();

    $this->actingAs($manager)
        ->put(route('appearance.update'), [
            'theme_preset' => 'indigo',
            'project_color' => '#123456',
            'project_color_dark' => '',
            'default_appearance' => 'system',
        ])
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHasNoErrors();

    $settings = app(SettingsRepositoryService::class);

    expect($settings->themePreset())->toBe('indigo')
        ->and($settings->projectColor())->toBe('#123456')
        ->and($settings->projectColorDark())->toBeNull();

    $this->actingAs($manager)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projectAppearance.themePreset', 'indigo')
            ->where('projectAppearance.themeCss.brandPrimary', '#123456')
            ->where('projectAppearance.themeCss.brandPrimaryDark', '#123456'));
});

it('keeps the stored theme preset when the request omits it', function () {
    app(SettingsRepositoryService::class)->setMany(['theme_preset' => 'rose']);

    $this->actingAs(projectSettingsManager())
        ->put(route('appearance.update'), ['default_appearance' => 'light'])
        ->assertSessionHasNoErrors();

    expect(app(SettingsRepositoryService::class)->themePreset())->toBe('rose');
});

it('rejects an unknown theme preset and invalid brand colours', function () {
    $this->actingAs(projectSettingsManager())
        ->put(route('appearance.update'), [
            'theme_preset' => 'neon',
            'project_color' => 'red',
            'project_color_dark' => '#12345',
            'default_appearance' => 'system',
        ])
        ->assertSessionHasErrors(['theme_preset', 'project_color', 'project_color_dark']);

    expect(app(SettingsRepositoryService::class)->themePreset())->toBe('teal');
});

it('renders the theme variables of a non-default preset in the HTML', function () {
    app(SettingsRepositoryService::class)->setMany(['theme_preset' => 'indigo']);
    $indigo = config('theme-presets.presets.indigo');

    $this->get(route('login'))
        ->assertSee('<style id="excubia-theme-css">', false)
        ->assertSee('--primary: '.$indigo['light']['primary'].';', false)
        ->assertSee('--primary: '.$indigo['dark']['primary'].';', false)
        ->assertDontSee('--brand-primary:', false);
});

it('renders the custom brand colour with an accessible foreground', function () {
    app(SettingsRepositoryService::class)->setMany(['theme_preset' => 'indigo', 'project_color' => '#FFEE00']);

    $this->get(route('login'))
        ->assertSee('--brand-primary: #FFEE00;', false)
        ->assertSee('--brand-primary-foreground: #0E171E;', false);
});
