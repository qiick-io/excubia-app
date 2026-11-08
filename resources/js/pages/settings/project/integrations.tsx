import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ProjectIntegrationsSettingsController from '@/actions/App/Http/Controllers/Settings/ProjectIntegrationsSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import BooleanField from '@/components/boolean-field';
import AlertFilterFields from '@/components/settings/alert-filter-fields';
import type {
    AlertFilter,
    AlertFilterProject,
} from '@/components/settings/alert-filter-fields';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editProfile } from '@/routes/profile';
import integrationsRoutes from '@/routes/project/integrations';
import type { BreadcrumbItem } from '@/types';

type Delivery = {
    event: string;
    eventId: string;
    at: string;
    message: string | null;
} | null;

type IntegrationsSettings = {
    webhook_url: string | null;
    has_webhook_secret: boolean;
    has_notifier_slack_webhook_url: boolean;
    has_notifier_telegram_bot_token: boolean;
    notifier_telegram_chat_id: string | null;
    channels: Record<Channel, AlertFilter>;
    event_severities: Record<string, string>;
    alert_cache_hit_ratio_below: number | null;
    alert_delivery_failures_enabled: boolean;
    alert_log_spike_per_hour: number | null;
    alert_ai_spend_spike_percent: number | null;
    alert_ai_error_rate_above: number | null;
    webhook_last_success: Delivery;
    webhook_last_error: Delivery;
};

type Channel = 'webhook' | 'slack' | 'telegram';

/**
 * Settings → Integrations (#22), copied from externa-core: the signed outbound webhook with
 * its delivery status, then the Slack and Telegram notifiers, each channel with its minimum
 * severity, events and projects; a pointer to the alert emails users set in their profile; the
 * default severity per event; and the optional runtime alert rules (#114). Secrets are
 * write-only: an empty field keeps the stored one. Needs `can-manage-alerts`.
 */
export default function ProjectIntegrationsSettings({
    project,
    events,
    severities,
    projects,
}: {
    project: IntegrationsSettings;
    events: string[];
    severities: string[];
    projects: AlertFilterProject[];
}) {
    const { t } = useTranslation();
    const [form, setForm] = useState({
        webhook_url: project.webhook_url ?? '',
        webhook_secret: '',
        notifier_slack_webhook_url: '',
        notifier_telegram_bot_token: '',
        notifier_telegram_chat_id: project.notifier_telegram_chat_id ?? '',
        alert_cache_hit_ratio_below: String(
            project.alert_cache_hit_ratio_below ?? '',
        ),
        alert_log_spike_per_hour: String(
            project.alert_log_spike_per_hour ?? '',
        ),
        alert_ai_spend_spike_percent: String(
            project.alert_ai_spend_spike_percent ?? '',
        ),
        alert_ai_error_rate_above: String(
            project.alert_ai_error_rate_above ?? '',
        ),
    });
    const [testing, setTesting] = useState<Channel | null>(null);
    const [channels, setChannels] = useState(project.channels);
    const [eventSeverities, setEventSeverities] = useState(
        project.event_severities,
    );

    const channelFilter = (
        channel: Channel,
        errors: Record<string, string | undefined>,
    ) => (
        <AlertFilterFields
            name={`channels[${channel}]`}
            errorKey={`channels.${channel}`}
            value={channels[channel]}
            onChange={(filter) =>
                setChannels((current) => ({ ...current, [channel]: filter }))
            }
            events={events}
            severities={severities}
            projects={projects}
            errors={errors}
        />
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.integrations.breadcrumb'),
            href: integrationsRoutes.edit.url(),
        },
    ];

    const setValue = (key: keyof typeof form, value: string): void =>
        setForm((current) => ({ ...current, [key]: value }));

    const sendTest = (channel: Channel): void => {
        setTesting(channel);
        router.post(
            integrationsRoutes.test.url(channel),
            {},
            { preserveScroll: true, onFinish: () => setTesting(null) },
        );
    };

    // 32 random bytes as hex, as externa-core's "Generate secret".
    const generateSecret = (): void => {
        const bytes = crypto.getRandomValues(new Uint8Array(32));
        setValue(
            'webhook_secret',
            Array.from(bytes, (byte) =>
                byte.toString(16).padStart(2, '0'),
            ).join(''),
        );
    };

    const isSlackConfigured = project.has_notifier_slack_webhook_url;
    const isTelegramConfigured =
        project.has_notifier_telegram_bot_token &&
        Boolean(project.notifier_telegram_chat_id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.integrations.head')} />
            <h1 className="sr-only">{t('settings.integrations.head')}</h1>

            <SettingsLayout>
                <Form
                    {...ProjectIntegrationsSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    onSuccess={() =>
                        setForm((current) => ({
                            ...current,
                            webhook_secret: '',
                            notifier_slack_webhook_url: '',
                            notifier_telegram_bot_token: '',
                        }))
                    }
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.integrations.webhookTitle',
                                    )}
                                    description={t(
                                        'settings.integrations.webhookDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <TextField
                                        name="webhook_url"
                                        type="url"
                                        label={t(
                                            'settings.integrations.webhookUrl',
                                        )}
                                        hint={t(
                                            'settings.integrations.webhookUrlHint',
                                        )}
                                        placeholder="https://example.com/webhooks/excubia"
                                        value={form.webhook_url}
                                        error={errors.webhook_url}
                                        onChange={(value) =>
                                            setValue('webhook_url', value)
                                        }
                                    />

                                    <Field
                                        data-invalid={
                                            errors.webhook_secret
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="webhook_secret">
                                            {t(
                                                'settings.integrations.webhookSecret',
                                            )}
                                        </FieldLabel>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Input
                                                id="webhook_secret"
                                                name="webhook_secret"
                                                type="password"
                                                autoComplete="new-password"
                                                value={form.webhook_secret}
                                                onChange={(event) =>
                                                    setValue(
                                                        'webhook_secret',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder={
                                                    project.has_webhook_secret
                                                        ? '••••••••'
                                                        : undefined
                                                }
                                                className="sm:flex-1"
                                                aria-invalid={
                                                    errors.webhook_secret
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.webhook_secret
                                                        ? 'webhook-secret-error'
                                                        : 'webhook-secret-hint'
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={generateSecret}
                                            >
                                                {t(
                                                    'settings.integrations.generateSecret',
                                                )}
                                            </Button>
                                        </div>
                                        <FieldDescription id="webhook-secret-hint">
                                            {t(
                                                'settings.integrations.webhookSecretHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="webhook-secret-error">
                                            {errors.webhook_secret}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>

                                {channelFilter('webhook', errors)}

                                <TestRow
                                    label={t('settings.integrations.sendTest')}
                                    hint={t(
                                        'settings.integrations.sendTestHint',
                                    )}
                                    disabled={
                                        testing !== null ||
                                        !project.webhook_url ||
                                        !project.has_webhook_secret
                                    }
                                    onClick={() => sendTest('webhook')}
                                />

                                <div className="grid gap-3">
                                    <Label>
                                        {t(
                                            'settings.integrations.deliveryTitle',
                                        )}
                                    </Label>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <DeliveryCard
                                            title={t(
                                                'settings.integrations.lastSuccess',
                                            )}
                                            delivery={
                                                project.webhook_last_success
                                            }
                                        />
                                        <DeliveryCard
                                            title={t(
                                                'settings.integrations.lastError',
                                            )}
                                            delivery={
                                                project.webhook_last_error
                                            }
                                            isError
                                        />
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.integrations.notifiersTitle',
                                    )}
                                    description={t(
                                        'settings.integrations.notifiersDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <TextField
                                        name="notifier_slack_webhook_url"
                                        type="url"
                                        label={t(
                                            'settings.integrations.slackUrl',
                                        )}
                                        hint={t(
                                            'settings.integrations.slackUrlHint',
                                        )}
                                        placeholder={
                                            project.has_notifier_slack_webhook_url
                                                ? '••••••••'
                                                : 'https://hooks.slack.com/services/…'
                                        }
                                        value={form.notifier_slack_webhook_url}
                                        error={
                                            errors.notifier_slack_webhook_url
                                        }
                                        onChange={(value) =>
                                            setValue(
                                                'notifier_slack_webhook_url',
                                                value,
                                            )
                                        }
                                    />
                                </FieldGroup>

                                {channelFilter('slack', errors)}

                                <TestRow
                                    label={t(
                                        'settings.integrations.sendTestSlack',
                                    )}
                                    hint={t(
                                        'settings.integrations.sendTestNotifierHint',
                                    )}
                                    disabled={
                                        testing !== null || !isSlackConfigured
                                    }
                                    onClick={() => sendTest('slack')}
                                />

                                <FieldGroup className="gap-6">
                                    <TextField
                                        name="notifier_telegram_bot_token"
                                        type="password"
                                        label={t(
                                            'settings.integrations.telegramToken',
                                        )}
                                        hint={t(
                                            'settings.integrations.telegramTokenHint',
                                        )}
                                        placeholder={
                                            project.has_notifier_telegram_bot_token
                                                ? '••••••••'
                                                : '123456:ABC…'
                                        }
                                        value={form.notifier_telegram_bot_token}
                                        error={
                                            errors.notifier_telegram_bot_token
                                        }
                                        onChange={(value) =>
                                            setValue(
                                                'notifier_telegram_bot_token',
                                                value,
                                            )
                                        }
                                    />
                                    <TextField
                                        name="notifier_telegram_chat_id"
                                        label={t(
                                            'settings.integrations.telegramChatId',
                                        )}
                                        hint={t(
                                            'settings.integrations.telegramChatIdHint',
                                        )}
                                        placeholder="-1001234567890"
                                        value={form.notifier_telegram_chat_id}
                                        error={errors.notifier_telegram_chat_id}
                                        onChange={(value) =>
                                            setValue(
                                                'notifier_telegram_chat_id',
                                                value,
                                            )
                                        }
                                    />
                                </FieldGroup>

                                {channelFilter('telegram', errors)}

                                <TestRow
                                    label={t(
                                        'settings.integrations.sendTestTelegram',
                                    )}
                                    hint={t(
                                        'settings.integrations.sendTestNotifierHint',
                                    )}
                                    disabled={
                                        testing !== null ||
                                        !isTelegramConfigured
                                    }
                                    onClick={() => sendTest('telegram')}
                                />
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.integrations.emailTitle',
                                    )}
                                    description={t(
                                        'settings.integrations.emailDescription',
                                    )}
                                />
                                <Button variant="outline" asChild>
                                    <Link href={editProfile()}>
                                        {t('settings.integrations.emailLink')}
                                    </Link>
                                </Button>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.integrations.severitiesTitle',
                                    )}
                                    description={t(
                                        'settings.integrations.severitiesDescription',
                                    )}
                                />
                                <div className="grid gap-3">
                                    {Object.entries(eventSeverities).map(
                                        ([event, severity]) => (
                                            <div
                                                key={event}
                                                className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <label
                                                    htmlFor={`severity-${event}`}
                                                    className="font-mono text-sm"
                                                >
                                                    {event}
                                                </label>
                                                <input
                                                    type="hidden"
                                                    name={`event_severities[${event}]`}
                                                    value={severity}
                                                />
                                                <Select
                                                    value={severity}
                                                    onValueChange={(value) =>
                                                        setEventSeverities(
                                                            (current) => ({
                                                                ...current,
                                                                [event]: value,
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id={`severity-${event}`}
                                                        className="w-full sm:w-48"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {severities.map(
                                                            (option) => (
                                                                <SelectItem
                                                                    key={option}
                                                                    value={
                                                                        option
                                                                    }
                                                                >
                                                                    {t(
                                                                        `report.severity.${option}`,
                                                                    )}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.integrations.runtimeRulesTitle',
                                    )}
                                    description={t(
                                        'settings.integrations.runtimeRulesDescription',
                                    )}
                                />
                                <FieldGroup className="gap-6">
                                    <TextField
                                        name="alert_cache_hit_ratio_below"
                                        type="number"
                                        label={t(
                                            'settings.integrations.cacheHitRatio',
                                        )}
                                        hint={t(
                                            'settings.integrations.cacheHitRatioHint',
                                        )}
                                        placeholder="80"
                                        value={form.alert_cache_hit_ratio_below}
                                        error={
                                            errors.alert_cache_hit_ratio_below
                                        }
                                        onChange={(value) =>
                                            setValue(
                                                'alert_cache_hit_ratio_below',
                                                value,
                                            )
                                        }
                                    />
                                    <TextField
                                        name="alert_log_spike_per_hour"
                                        type="number"
                                        label={t(
                                            'settings.integrations.logSpike',
                                        )}
                                        hint={t(
                                            'settings.integrations.logSpikeHint',
                                        )}
                                        placeholder="50"
                                        value={form.alert_log_spike_per_hour}
                                        error={errors.alert_log_spike_per_hour}
                                        onChange={(value) =>
                                            setValue(
                                                'alert_log_spike_per_hour',
                                                value,
                                            )
                                        }
                                    />
                                    <BooleanField
                                        name="alert_delivery_failures_enabled"
                                        label={t(
                                            'settings.integrations.deliveryFailures',
                                        )}
                                        defaultChecked={
                                            project.alert_delivery_failures_enabled
                                        }
                                    />
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.integrations.aiRulesTitle',
                                    )}
                                    description={t(
                                        'settings.integrations.aiRulesDescription',
                                    )}
                                />
                                <FieldGroup className="gap-6">
                                    <TextField
                                        name="alert_ai_spend_spike_percent"
                                        type="number"
                                        label={t(
                                            'settings.integrations.aiSpendSpike',
                                        )}
                                        hint={t(
                                            'settings.integrations.aiSpendSpikeHint',
                                        )}
                                        placeholder="300"
                                        value={
                                            form.alert_ai_spend_spike_percent
                                        }
                                        error={
                                            errors.alert_ai_spend_spike_percent
                                        }
                                        onChange={(value) =>
                                            setValue(
                                                'alert_ai_spend_spike_percent',
                                                value,
                                            )
                                        }
                                    />
                                    <TextField
                                        name="alert_ai_error_rate_above"
                                        type="number"
                                        label={t(
                                            'settings.integrations.aiErrorRate',
                                        )}
                                        hint={t(
                                            'settings.integrations.aiErrorRateHint',
                                        )}
                                        placeholder="10"
                                        value={form.alert_ai_error_rate_above}
                                        error={errors.alert_ai_error_rate_above}
                                        onChange={(value) =>
                                            setValue(
                                                'alert_ai_error_rate_above',
                                                value,
                                            )
                                        }
                                    />
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="project-integrations-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}

/** One text field with its hint and error, as externa-core's integration fields. */
function TextField({
    name,
    label,
    hint,
    value,
    error,
    onChange,
    type = 'text',
    placeholder,
}: {
    name: string;
    label: string;
    hint: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
    type?: 'text' | 'url' | 'password' | 'number';
    placeholder?: string;
}) {
    const id = name.replaceAll('_', '-');

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Input
                id={name}
                name={name}
                type={type}
                autoComplete={type === 'password' ? 'new-password' : 'off'}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : `${id}-hint`}
            />
            <FieldDescription id={`${id}-hint`}>{hint}</FieldDescription>
            <FieldError id={`${id}-error`}>{error}</FieldError>
        </Field>
    );
}

/** "Send test" with its hint on one row, as externa-core. */
function TestRow({
    label,
    hint,
    disabled,
    onClick,
}: {
    label: string;
    hint: string;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <Button
                type="button"
                variant="secondary"
                disabled={disabled}
                onClick={onClick}
            >
                {label}
            </Button>
            <p className="text-sm text-muted-foreground">{hint}</p>
        </div>
    );
}

/** Last success / last error of the webhook, as externa-core's delivery cards. */
function DeliveryCard({
    title,
    delivery,
    isError = false,
}: {
    title: string;
    delivery: Delivery;
    isError?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-1 rounded-md border p-3">
            <p className="text-sm font-medium">{title}</p>
            {delivery === null ? (
                <p className="text-sm text-muted-foreground">
                    {t('settings.integrations.deliveryNone')}
                </p>
            ) : (
                <>
                    <p className="text-sm">
                        <code className="font-mono">{delivery.event}</code>{' '}
                        <span className="text-muted-foreground">
                            · {new Date(delivery.at).toLocaleString()}
                        </span>
                    </p>
                    {isError && delivery.message ? (
                        <p className="text-sm break-words text-destructive">
                            {delivery.message}
                        </p>
                    ) : null}
                    <p className="font-mono text-xs text-muted-foreground">
                        {delivery.eventId}
                    </p>
                </>
            )}
        </div>
    );
}
