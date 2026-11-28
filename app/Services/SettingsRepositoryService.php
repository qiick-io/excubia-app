<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Data\AlertFilterData;
use App\Enums\AiToolKindEnum;
use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Enums\ThemePresetEnum;
use App\Models\Setting;
use App\Rules\ContextSpecificPasswordRule;
use Illuminate\Validation\Rules\Password;
use Qiick\Settings\Settings;
use Qiick\Settings\UndecryptableSetting;

/**
 * Excubia's settings over qiick/laravel-settings, with typed getters for the code that uses them
 * (login, password rule, 2FA requirement, report history, SSO, UI locale, default appearance,
 * sidebar modules, alerts).
 *
 * Every key is declared by App\Settings\ExcubiaSettingsSchema from TYPES and defaults(), at
 * scope `instance`, group `app`. Public method signatures stay flat-keyed so callers do not change.
 * The package memoises reads per request or job and forgets them after a write commits, so a queue
 * worker sees a change made by the web process.
 *
 * Secrets (SECRET_KEYS: the OIDC client secret, the Slack incoming-webhook URL, which is a bearer
 * secret, and the Telegram bot token) are encrypted by the package, absent from all(), and replaced
 * by `has_<key>` flags in forEdit().
 */
class SettingsRepositoryService
{
    public const string SCOPE_INSTANCE = 'instance';

    /** Empty string sentinel for instance scope (NOT NULL; see signaculum-core #205). */
    public const string SCOPE_ID_INSTANCE = '';

    public const string GROUP_APP = 'app';

    public const PASSWORD_POLICIES = ['weak', 'medium', 'strong'];

    public const int MINIMUM_PASSWORD_LENGTH = 8;

    public const APPEARANCES = ['system', 'light', 'dark'];

    public const SECRET_KEYS = ['oidc_client_secret', 'notifier_slack_webhook_url', 'notifier_telegram_bot_token'];

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
        ['id' => 'project-groups', 'locked' => false],
        ['id' => 'fleet', 'locked' => false],
        ['id' => 'assistant', 'locked' => false],
        ['id' => 'activity', 'locked' => false],
        ['id' => 'users', 'locked' => false],
        ['id' => 'groups', 'locked' => false],
        ['id' => 'settings', 'locked' => true],
    ];

    /**
     * Uploaded branding files: the public asset name → the setting holding its path on the files
     * disk (`branding/`, SaveBrandingFilesAction::disk()). Excubia has no file manager.
     */
    public const BRANDING_ASSETS = [
        'logo' => 'project_logo',
        'logo-dark' => 'project_logo_dark',
        'favicon' => 'public_favicon',
    ];

    /**
     * The declared type of every key (ExcubiaSettingsSchema). Values written through setMany()
     * are coerced to it, so a numeric string from a form is stored as an integer.
     */
    public const TYPES = [
        'app_name' => 'string',
        'description' => 'string',
        'url' => 'string',
        'report_issue_url' => 'string',
        'report_bug_url' => 'string',
        'report_error_url' => 'string',
        'default_language' => 'string',
        'default_appearance' => 'string',
        'theme_preset' => 'string',
        'project_color' => 'string',
        'project_color_dark' => 'string',
        'project_logo' => 'string',
        'project_logo_dark' => 'string',
        'public_favicon' => 'string',
        'sidebar_modules' => 'json',
        'password_policy' => 'string',
        'login_max_attempts' => 'int',
        'two_factor_required' => 'bool',
        'reports_kept_per_project' => 'int',
        'stale_after_hours' => 'int',
        'pennant_stale_after_days' => 'int',
        'runtime_retention_days' => 'int',
        'exceptions_retention_days' => 'int',
        'oidc_enabled' => 'bool',
        'oidc_issuer' => 'string',
        'oidc_client_id' => 'string',
        'oidc_client_secret' => 'string',
        'oidc_button_label' => 'string',
        'oidc_jit_provisioning' => 'bool',
        'oidc_auto_link' => 'bool',
        'oidc_only' => 'bool',
        'ai_disabled_tools' => 'json',
        'ai_enabled_tools' => 'json',
        'notifier_slack_webhook_url' => 'string',
        'notifier_telegram_bot_token' => 'string',
        'notifier_telegram_chat_id' => 'string',
        'alert_channels' => 'json',
        'alert_event_severities' => 'json',
        'alert_cache_hit_ratio_below' => 'int',
        'alert_delivery_failures_enabled' => 'bool',
        'alert_log_spike_per_hour' => 'int',
        'alert_ai_spend_spike_percent' => 'int',
        'alert_ai_error_rate_above' => 'int',
        'alert_billing_webhook_failures_per_hour' => 'int',
        'alert_billing_payment_failed_enabled' => 'bool',
        'alert_auth_failed_logins_per_hour' => 'int',
        'alert_scout_index_failures_per_hour' => 'int',
        'ai_prices' => 'json',
        'ai_prices_updated_on' => 'string',
    ];

    public function __construct(private Settings $settings) {}

    /**
     * A value in the declared type: null stays null (the default applies), numeric strings become
     * numbers, scalars become strings. A value that cannot be coerced is returned as it is, and
     * the package refuses it.
     */
    public static function coerce(string $type, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $type === 'int' && is_numeric($value) => (int) $value,
            $type === 'bool' && is_scalar($value) => (bool) $value,
            $type === 'string' && is_scalar($value) => (string) $value,
            default => $value,
        };
    }

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
            'pennant_stale_after_days' => config('excubia.pennant_stale_after_days'),
            'runtime_retention_days' => config('excubia.runtime_retention_days'),
            'exceptions_retention_days' => config('excubia.exceptions_retention_days'),
            'oidc_enabled' => false,
            'oidc_issuer' => null,
            'oidc_client_id' => null,
            'oidc_client_secret' => null,
            'oidc_button_label' => null,
            'oidc_jit_provisioning' => false,
            // Off by default (qiick/laravel-oidc): a matching email links an SSO identity only for a
            // trusted IdP. Installs that configured SSO before the package keep linking (migration).
            'oidc_auto_link' => false,
            // SSO-only mode: the password form is hidden and only break-glass accounts sign in locally.
            'oidc_only' => false,
            // Tools switched off in Settings → AI (#320); `ai_enabled_tools` is the list saved before it.
            'ai_disabled_tools' => null,
            'ai_enabled_tools' => null,
            'notifier_slack_webhook_url' => null,
            'notifier_telegram_bot_token' => null,
            'notifier_telegram_chat_id' => null,
            // Per channel (webhook, slack, telegram): AlertFilterData::toArray(); see alertChannelFilter().
            'alert_channels' => null,
            // Event → severity, overriding AlertEventEnum::defaultSeverity().
            'alert_event_severities' => null,
            // Optional runtime alert rules (#107, #114): null / false is off.
            'alert_cache_hit_ratio_below' => null,
            'alert_delivery_failures_enabled' => false,
            'alert_log_spike_per_hour' => null,
            // AI usage alert rules (#129): null is off.
            'alert_ai_spend_spike_percent' => null,
            'alert_ai_error_rate_above' => null,
            // Billing webhook alert rules (#178): null / false is off.
            'alert_billing_webhook_failures_per_hour' => null,
            'alert_billing_payment_failed_enabled' => false,
            // Failed-login alert rule (#180): null is off.
            'alert_auth_failed_logins_per_hour' => null,
            // Scout index failure alert rule (#184): null is off.
            'alert_scout_index_failures_per_hour' => null,
            // AI price table (#129): null uses AiPriceService::defaults().
            'ai_prices' => null,
            'ai_prices_updated_on' => null,
        ];
    }

    /**
     * Every setting, stored values over defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->settings->all(self::GROUP_APP);
    }

    /**
     * Settings for the edit page: secrets replaced by `has_<key>` flags, never sent in plain text.
     *
     * @return array<string, mixed>
     */
    public function forEdit(): array
    {
        return $this->settings->forEdit(self::GROUP_APP);
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
            if (array_key_exists($key, $values) && (! is_string($values[$key]) || $values[$key] === '')) {
                unset($values[$key]);
            }
        }

        $this->setMany($values);
    }

    /**
     * Saves the given keys in one transaction, each coerced to its declared type.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws \InvalidArgumentException for an undeclared key or a value of the wrong type
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $values[$key] = self::coerce(self::TYPES[$key] ?? 'string', $value);
        }

        $this->settings->set($values);
    }

    /**
     * Clears the given keys; their defaults apply again.
     *
     * @param  list<string>  $keys
     */
    public function forget(array $keys): void
    {
        $undeclaredKeys = array_values(array_diff($keys, array_keys(self::TYPES)));

        foreach (array_diff($keys, $undeclaredKeys) as $key) {
            $this->settings->forget($key);
        }

        // ponytail: rows no schema declares (the rule rows saved before #166) cannot go through
        // the package, which refuses undeclared keys; they are deleted directly. Drop at 1.0.0.
        if ($undeclaredKeys !== []) {
            Setting::query()
                ->where('scope', self::SCOPE_INSTANCE)
                ->where('scope_id', self::SCOPE_ID_INSTANCE)
                ->where('group', self::GROUP_APP)
                ->whereIn('key', $undeclaredKeys)
                ->delete();
        }
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
        $passwordRule = match ($this->passwordPolicy()) {
            'weak' => Password::min(self::MINIMUM_PASSWORD_LENGTH),
            'medium' => Password::min(12),
            default => Password::min(15)->uncompromised(),
        };

        $deploymentTerms = [$this->appName(), ...explode(',', (string) config('excubia.password_denylist'))];

        return $passwordRule->rules([new ContextSpecificPasswordRule($deploymentTerms)]);
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
     * Assistant tools enabled in Settings → AI: every tool that is not switched off, so a tool
     * added by an upgrade (such as the write tools of #320) is on.
     *
     * Settings saved before #320 kept the enabled tools (`ai_enabled_tools`), all of them read
     * tools; until the page is saved again, the read tools missing from that list stay off.
     *
     * @return list<string>
     */
    public function aiEnabledTools(): array
    {
        $values = $this->all();
        $disabled = $values['ai_disabled_tools'];

        if (! is_array($disabled) && is_array($values['ai_enabled_tools'])) {
            $disabled = array_diff(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Read), $values['ai_enabled_tools']);
        }

        return array_values(array_diff(array_keys(ExcubiaAssistantAgent::TOOLS), (array) $disabled));
    }

    /** Days runtime aggregates are kept (Data retention). */
    public function runtimeRetentionDays(): int
    {
        return $this->integer('runtime_retention_days', 1, 3650);
    }

    /**
     * Days a Laravel Pennant feature must be checked with always the same value before the
     * Runtime tab lists it as a stale flag (#189, Checks).
     */
    public function pennantStaleAfterDays(): int
    {
        return $this->integer('pennant_stale_after_days', 1, 3650);
    }

    /** Days exception groups are kept after they were last seen (Data retention). */
    public function exceptionsRetentionDays(): int
    {
        return $this->integer('exceptions_retention_days', 1, 3650);
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

    public function isOidcAutoLinkEnabled(): bool
    {
        return (bool) $this->all()['oidc_auto_link'];
    }

    public function isOidcOnlyEnabled(): bool
    {
        return (bool) $this->all()['oidc_only'];
    }

    /**
     * Decrypted client secret, or null when unset or unreadable (for example after an APP_KEY change).
     */
    public function oidcClientSecret(): ?string
    {
        return $this->secret('oidc_client_secret');
    }

    public function slackWebhookUrl(): ?string
    {
        return $this->secret('notifier_slack_webhook_url');
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
     * The filters of one alert channel (#22). Until saved, the webhook takes every event and
     * severity, Slack and Telegram every event from `high`, or the shared filter they had before
     * per-channel filters (`notifier_event_types`, `notifier_minimum_severity`).
     */
    public function alertChannelFilter(string $channel): AlertFilterData
    {
        $values = $this->all();
        $stored = is_array($values['alert_channels']) ? ($values['alert_channels'][$channel] ?? null) : null;

        if ($stored === null && $channel !== 'webhook' && array_key_exists('notifier_minimum_severity', $values)) {
            $stored = ['minimum_severity' => $values['notifier_minimum_severity'], 'event_types' => $values['notifier_event_types'] ?? null];
        }

        return AlertFilterData::fromStored($stored, $channel === 'webhook' ? SeverityEnum::Info : SeverityEnum::High);
    }

    /**
     * The severity of an event that does not take one from a finding: the admin's choice in
     * Settings → Integrations, else the built-in default.
     */
    public function alertEventSeverity(AlertEventEnum $event): SeverityEnum
    {
        $stored = $this->all()['alert_event_severities'];
        $value = is_array($stored) && in_array($event->value, AlertEventEnum::severityEditableValues(), true) ? ($stored[$event->value] ?? null) : null;

        return SeverityEnum::tryFrom((string) $value) ?? $event->defaultSeverity();
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

    /**
     * Today's estimated AI spend of a project, as a percentage of its average day of the 7 days
     * before, from which it alerts (#129); null when off.
     */
    public function alertAiSpendSpikePercent(): ?int
    {
        return $this->positiveInteger('alert_ai_spend_spike_percent');
    }

    /** AI error rate (percent of calls in the last hour) above which a project alerts (#129); null when off. */
    public function alertAiErrorRateAbove(): ?int
    {
        return $this->positiveInteger('alert_ai_error_rate_above');
    }

    /** Failed billing webhooks per provider and hour from which a project alerts (#178); null when off. */
    public function alertBillingWebhookFailuresPerHour(): ?int
    {
        return $this->positiveInteger('alert_billing_webhook_failures_per_hour');
    }

    /** Whether failed payments reported by billing webhooks alert (#178). */
    public function shouldAlertBillingPaymentFailures(): bool
    {
        return (bool) $this->all()['alert_billing_payment_failed_enabled'];
    }

    /** Failed logins per auth guard and hour from which a project alerts (#180); null when off. */
    public function alertAuthFailedLoginsPerHour(): ?int
    {
        return $this->positiveInteger('alert_auth_failed_logins_per_hour');
    }

    /** Failed Scout index jobs per model and hour from which a project alerts (#184); null when off. */
    public function alertScoutIndexFailuresPerHour(): ?int
    {
        return $this->positiveInteger('alert_scout_index_failures_per_hour');
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
        try {
            $plain = $this->settings->secret($key)?->reveal();
        } catch (UndecryptableSetting) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
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
