<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Enums\ThemePresetEnum;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Application settings stored in the `settings` table, merged over defaults, with typed getters
 * for the code that uses them (login, password rule, 2FA requirement, report history, SSO,
 * UI locale, default appearance, sidebar modules).
 *
 * Secrets (`oidc_client_secret`) are stored encrypted and left out of forEdit().
 *
 * Registered as a singleton; values are read once per instance and reloaded after a write.
 */
class SettingsRepositoryService
{
    public const PASSWORD_POLICIES = ['weak', 'medium', 'strong'];

    public const APPEARANCES = ['system', 'light', 'dark'];

    public const SECRET_KEYS = ['oidc_client_secret', 'webhook_secret', 'notifier_telegram_bot_token'];

    /**
     * Main sidebar modules in their default order: the single source for the sidebar settings
     * page, its validation and the shared `projectSettings.sidebarModules`. Locked modules are
     * always enabled. A module added here shows up for existing installs next to its neighbour.
     *
     * @var list<array{id: string, locked: bool}>
     */
    public const SIDEBAR_MODULES = [
        ['id' => 'dashboard', 'locked' => true],
        ['id' => 'projects', 'locked' => false],
        ['id' => 'assistant', 'locked' => false],
        ['id' => 'activity', 'locked' => false],
        ['id' => 'users', 'locked' => false],
        ['id' => 'groups', 'locked' => false],
        ['id' => 'settings', 'locked' => true],
    ];

    /**
     * Uploaded branding files: the public asset name → the setting holding its path on the local
     * disk (`branding/`). Excubia has no file manager, so they live next to the app.
     */
    public const BRANDING_ASSETS = [
        'logo' => 'project_logo',
        'logo-dark' => 'project_logo_dark',
        'favicon' => 'public_favicon',
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * Defaults for every key. Names match qiick-io/externa-core where the setting exists there.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'app_name' => null,
            'description' => null,
            'url' => null,
            'report_issue_url' => null,
            'report_bug_url' => null,
            'report_error_url' => null,
            'default_language' => $this->configLanguage(),
            'default_appearance' => 'system',
            'theme_preset' => ThemePresetEnum::default()->value,
            'project_color' => null,
            'project_color_dark' => null,
            'project_logo' => null,
            'project_logo_dark' => null,
            'public_favicon' => null,
            'sidebar_modules' => null,
            'password_policy' => app()->isProduction() ? 'strong' : 'weak',
            'login_max_attempts' => 5,
            'two_factor_required' => false,
            'reports_kept_per_project' => config('excubia.reports_kept_per_project'),
            'stale_after_hours' => config('excubia.stale_after_hours'),
            'runtime_retention_days' => config('excubia.runtime_retention_days'),
            'exceptions_retention_days' => config('excubia.exceptions_retention_days'),
            'activity_retention_days' => config('activitylog.clean_after_days'),
            'oidc_enabled' => false,
            'oidc_issuer' => null,
            'oidc_client_id' => null,
            'oidc_client_secret' => null,
            'oidc_button_label' => null,
            'oidc_jit_provisioning' => false,
            'ai_enabled_tools' => null,
            'webhook_url' => null,
            'webhook_secret' => null,
            'notifier_slack_webhook_url' => null,
            'notifier_telegram_bot_token' => null,
            'notifier_telegram_chat_id' => null,
            'notifier_event_types' => null,
            'notifier_minimum_severity' => SeverityEnum::High->value,
            // Optional runtime alert rules (#107, #114): null / false is off.
            'alert_cache_hit_ratio_below' => null,
            'alert_delivery_failures_enabled' => false,
            'alert_log_spike_per_hour' => null,
            'webhook_last_success' => null,
            'webhook_last_error' => null,
        ];
    }

    /**
     * Every setting, stored values over defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values ??= array_merge(
            $this->defaults(),
            Setting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * Settings for the edit page: secrets replaced by `has_<key>` flags, never sent in plain text.
     *
     * @return array<string, mixed>
     */
    public function forEdit(): array
    {
        $values = $this->all();

        foreach (self::SECRET_KEYS as $key) {
            $values['has_'.$key] = is_string($values[$key]) && $values[$key] !== '';
            unset($values[$key]);
        }

        return $values;
    }

    /**
     * Only the given keys of forEdit(), for one settings page.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function forEditOnly(array $keys): array
    {
        return array_intersect_key($this->forEdit(), array_flip($keys));
    }

    /**
     * Settings shared with every Inertia page (`projectSettings`).
     *
     * @return array{sidebarModules: list<array{id: string, enabled: bool, locked: bool}>, reportBugUrl: string|null}
     */
    public function shared(): array
    {
        return [
            'sidebarModules' => $this->sidebarModules(),
            'reportBugUrl' => $this->reportBugUrl(),
        ];
    }

    /**
     * Saves the given keys in one transaction. Secret keys are encrypted; a null secret keeps
     * the stored one.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (self::SECRET_KEYS as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if (is_string($values[$key]) && $values[$key] !== '') {
                $values[$key] = Crypt::encryptString($values[$key]);
            } else {
                unset($values[$key]);
            }
        }

        $this->setMany($values);
    }

    /**
     * Saves the given keys in one transaction.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        $this->values = null;
    }

    public function appName(): string
    {
        $name = $this->all()['app_name'];

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name');
    }

    /**
     * Public URLs of the uploaded logo, dark logo and favicon (null when not uploaded), served by
     * BrandingController. The stored file name changes on every upload, so it busts caches.
     *
     * @return array{logoUrl: string|null, logoDarkUrl: string|null, faviconUrl: string|null}
     */
    public function brandingUrls(): array
    {
        $url = function (string $asset): ?string {
            $path = $this->string(self::BRANDING_ASSETS[$asset]);

            return $path === null ? null : route('branding.show', ['asset' => $asset, 'v' => pathinfo($path, PATHINFO_FILENAME)]);
        };

        return ['logoUrl' => $url('logo'), 'logoDarkUrl' => $url('logo-dark'), 'faviconUrl' => $url('favicon')];
    }

    public function reportBugUrl(): ?string
    {
        return $this->string('report_bug_url');
    }

    /**
     * Default UI locale for users without a personal or cookie choice.
     */
    public function defaultLanguage(): string
    {
        $language = $this->all()['default_language'];

        return is_string($language) && in_array($language, $this->availableLanguages(), true)
            ? $language
            : $this->configLanguage();
    }

    /**
     * Light/dark/system default for visitors without an `appearance` cookie.
     */
    public function defaultAppearance(): string
    {
        $appearance = $this->all()['default_appearance'];

        return is_string($appearance) && in_array($appearance, self::APPEARANCES, true) ? $appearance : 'system';
    }

    public function themePreset(): string
    {
        $preset = $this->all()['theme_preset'];

        return ThemePresetEnum::tryFromNullable(is_string($preset) ? $preset : null)->value;
    }

    /**
     * Custom brand colour (`#RRGGBB`) that overrides the preset primary, or null.
     */
    public function projectColor(): ?string
    {
        return $this->hexColor('project_color');
    }

    /**
     * Custom dark-mode brand colour, or null (the light one is used then).
     */
    public function projectColorDark(): ?string
    {
        return $this->hexColor('project_color_dark');
    }

    /**
     * @return list<string>
     */
    public function sidebarModuleIds(): array
    {
        return array_column(self::SIDEBAR_MODULES, 'id');
    }

    /**
     * Sidebar modules in the saved order with their visibility. Unknown stored ids are dropped,
     * locked modules are forced on, and modules missing from the stored list (new ones) are
     * inserted after their default predecessor.
     *
     * @return list<array{id: string, enabled: bool, locked: bool}>
     */
    public function sidebarModules(): array
    {
        $locked = array_column(self::SIDEBAR_MODULES, 'locked', 'id');
        $stored = $this->all()['sidebar_modules'];
        $modules = [];

        foreach (is_array($stored) ? $stored : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (! is_string($id) || ! array_key_exists($id, $locked) || array_key_exists($id, $modules)) {
                continue;
            }

            $modules[$id] = ['id' => $id, 'enabled' => $locked[$id] || (bool) ($entry['enabled'] ?? true), 'locked' => $locked[$id]];
        }

        $previousId = null;

        foreach (self::SIDEBAR_MODULES as $default) {
            if (! array_key_exists($default['id'], $modules)) {
                $module = ['id' => $default['id'], 'enabled' => true, 'locked' => $default['locked']];
                $position = $previousId === null ? 0 : (int) array_search($previousId, array_keys($modules), true) + 1;
                $modules = array_slice($modules, 0, $position, true) + [$default['id'] => $module] + array_slice($modules, $position, null, true);
            }

            $previousId = $default['id'];
        }

        return array_values($modules);
    }

    public function passwordPolicy(): string
    {
        $policy = $this->all()['password_policy'];

        return in_array($policy, self::PASSWORD_POLICIES, true) ? $policy : 'strong';
    }

    /**
     * The password rule of the saved policy, used as `Password::defaults()`.
     */
    public function passwordRule(): Password
    {
        return match ($this->passwordPolicy()) {
            'weak' => Password::min(6),
            'medium' => Password::min(8)->mixedCase()->numbers(),
            default => Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised(),
        };
    }

    public function loginMaxAttempts(): int
    {
        return $this->integer('login_max_attempts', 1, 100);
    }

    public function isTwoFactorRequired(): bool
    {
        return (bool) $this->all()['two_factor_required'];
    }

    public function reportsKeptPerProject(): int
    {
        return $this->integer('reports_kept_per_project', 1, 1000);
    }

    public function staleAfterHours(): int
    {
        return $this->integer('stale_after_hours', 1, 8760);
    }

    /**
     * Assistant tools enabled in Settings → AI; every tool until the setting is saved.
     *
     * @return list<string>
     */
    public function aiEnabledTools(): array
    {
        $value = $this->all()['ai_enabled_tools'];
        $names = array_keys(ExcubiaAssistantAgent::TOOLS);

        return is_array($value) ? array_values(array_intersect($names, $value)) : $names;
    }

    /** Days runtime aggregates are kept (Data retention). */
    public function runtimeRetentionDays(): int
    {
        return $this->integer('runtime_retention_days', 1, 3650);
    }

    /** Days exception groups are kept after they were last seen (Data retention). */
    public function exceptionsRetentionDays(): int
    {
        return $this->integer('exceptions_retention_days', 1, 3650);
    }

    /** Days activity log entries are kept (Data retention). */
    public function activityRetentionDays(): int
    {
        return $this->integer('activity_retention_days', 1, 3650);
    }

    public function isOidcEnabled(): bool
    {
        return (bool) $this->all()['oidc_enabled'];
    }

    public function oidcIssuer(): ?string
    {
        return $this->string('oidc_issuer');
    }

    public function oidcClientId(): ?string
    {
        return $this->string('oidc_client_id');
    }

    public function oidcButtonLabel(): ?string
    {
        return $this->string('oidc_button_label');
    }

    public function isOidcJitProvisioningEnabled(): bool
    {
        return (bool) $this->all()['oidc_jit_provisioning'];
    }

    /**
     * Decrypted client secret, or null when unset or unreadable (for example after an APP_KEY change).
     */
    public function oidcClientSecret(): ?string
    {
        return $this->secret('oidc_client_secret');
    }

    public function webhookUrl(): ?string
    {
        return $this->string('webhook_url');
    }

    public function webhookSecret(): ?string
    {
        return $this->secret('webhook_secret');
    }

    public function slackWebhookUrl(): ?string
    {
        return $this->string('notifier_slack_webhook_url');
    }

    public function telegramBotToken(): ?string
    {
        return $this->secret('notifier_telegram_bot_token');
    }

    public function telegramChatId(): ?string
    {
        return $this->string('notifier_telegram_chat_id');
    }

    /**
     * Events the Slack / Telegram notifiers send; every event until the setting is saved.
     *
     * @return list<string>
     */
    public function notifierEventTypes(): array
    {
        $value = $this->all()['notifier_event_types'];
        $names = AlertEventEnum::selectableValues();

        return is_array($value) ? array_values(array_intersect($names, $value)) : $names;
    }

    /** Alerts below this severity are not sent to Slack or Telegram. */
    public function notifierMinimumSeverity(): SeverityEnum
    {
        return SeverityEnum::tryFrom((string) $this->all()['notifier_minimum_severity']) ?? SeverityEnum::High;
    }

    /** Cache hit ratio (percent) under which a store alerts, or null when the rule is off. */
    public function alertCacheHitRatioBelow(): ?int
    {
        return $this->positiveInteger('alert_cache_hit_ratio_below');
    }

    /** Whether failed mails and notifications alert. */
    public function shouldAlertDeliveryFailures(): bool
    {
        return (bool) $this->all()['alert_delivery_failures_enabled'];
    }

    /** Error-level log entries per channel and hour from which a channel alerts, or null when off. */
    public function alertLogSpikePerHour(): ?int
    {
        return $this->positiveInteger('alert_log_spike_per_hour');
    }

    private function positiveInteger(string $key): ?int
    {
        $value = $this->all()[$key];

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * A decrypted secret setting, or null when it is missing or unreadable.
     */
    private function secret(string $key): ?string
    {
        $stored = $this->string($key);

        if ($stored === null) {
            return null;
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }

        return $plain !== '' ? $plain : null;
    }

    /**
     * SSO is enabled and fully configured, so the login page shows the button.
     */
    public function isOidcLoginAvailable(): bool
    {
        return $this->isOidcEnabled()
            && $this->oidcIssuer() !== null
            && $this->oidcClientId() !== null
            && $this->oidcClientSecret() !== null;
    }

    /**
     * @return list<string>
     */
    private function availableLanguages(): array
    {
        return array_keys((array) config('i18n.available_locales', ['en' => 'English']));
    }

    private function configLanguage(): string
    {
        $locale = (string) config('app.locale', 'en');

        return in_array($locale, $this->availableLanguages(), true) ? $locale : 'en';
    }

    private function hexColor(string $key): ?string
    {
        $value = $this->string($key);

        return $value !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : null;
    }

    private function string(string $key): ?string
    {
        $value = $this->all()[$key];

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integer(string $key, int $min, int $max): int
    {
        $value = $this->all()[$key];

        return max($min, min($max, is_numeric($value) ? (int) $value : (int) $this->defaults()[$key]));
    }
}
