import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ProjectSecuritySettingsController from '@/actions/App/Http/Controllers/Settings/ProjectSecuritySettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
import { edit as editSecurity } from '@/routes/project/security';
import type {
    BreadcrumbItem,
    PasswordPolicy,
    ProjectSettingsForm,
} from '@/types';
import { edit as editProfile } from '@/routes/profile';

type SecurityProjectSettings = Pick<
    ProjectSettingsForm,
    | 'password_policy'
    | 'login_max_attempts'
    | 'two_factor_required'
    | 'oidc_enabled'
    | 'oidc_issuer'
    | 'oidc_client_id'
    | 'has_oidc_client_secret'
    | 'oidc_button_label'
    | 'oidc_jit_provisioning'
    | 'oidc_auto_link'
    | 'oidc_only'
>;

type Props = {
    project: SecurityProjectSettings;
    passwordPolicies: PasswordPolicy[];
    oidcCallbackUrl: string;
    oidcBackchannelLogoutUrl: string;
};

const SSO_TOGGLE_NAMES = [
    'oidc_auto_link',
    'oidc_jit_provisioning',
    'oidc_only',
] as const;

type SsoToggleName = (typeof SSO_TOGGLE_NAMES)[number];

const SSO_TOGGLE_TEXTS = {
    oidc_auto_link: {
        label: 'settings.project.oidcAutoLink',
        hint: 'settings.project.oidcAutoLinkHint',
    },
    oidc_jit_provisioning: {
        label: 'settings.project.oidcJitProvisioning',
        hint: 'settings.project.oidcJitProvisioningHint',
    },
    oidc_only: {
        label: 'settings.project.oidcOnly',
        hint: 'settings.project.oidcOnlyHint',
    },
} as const satisfies Record<SsoToggleName, { label: string; hint: string }>;

/**
 * One SSO on/off setting with its hint; the form posts it through a hidden input.
 */
function SsoToggleField({
    name,
    label,
    hint,
    checked,
    error,
    onCheckedChange,
}: {
    name: SsoToggleName;
    label: string;
    hint: string;
    checked: boolean;
    error?: string;
    onCheckedChange: (checked: boolean) => void;
}) {
    const fieldId = name.replaceAll('_', '-');

    return (
        <Field data-invalid={error ? true : undefined}>
            <div className="flex items-center gap-3">
                <Checkbox
                    id={name}
                    checked={checked}
                    onCheckedChange={(state) => onCheckedChange(state === true)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={
                        error ? `${fieldId}-error` : `${fieldId}-hint`
                    }
                />
                <FieldLabel htmlFor={name}>{label}</FieldLabel>
            </div>
            <FieldDescription id={`${fieldId}-hint`} className="pl-8">
                {hint}
            </FieldDescription>
            <FieldError id={`${fieldId}-error`}>{error}</FieldError>
        </Field>
    );
}

function buildFormState(project: SecurityProjectSettings) {
    return {
        password_policy: project.password_policy,
        login_max_attempts: project.login_max_attempts,
        two_factor_required: project.two_factor_required,
        oidc_enabled: project.oidc_enabled ?? false,
        oidc_issuer: project.oidc_issuer ?? '',
        oidc_client_id: project.oidc_client_id ?? '',
        oidc_client_secret: '',
        oidc_button_label: project.oidc_button_label ?? '',
        oidc_jit_provisioning: project.oidc_jit_provisioning ?? false,
        oidc_auto_link: project.oidc_auto_link ?? false,
        oidc_only: project.oidc_only ?? false,
    };
}

/**
 * Authentication & security project settings.
 */
export default function ProjectSecuritySettingsPage({
    project,
    passwordPolicies,
    oidcCallbackUrl,
    oidcBackchannelLogoutUrl,
}: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState(() => buildFormState(project));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: editProfile() },
        {
            title: t('settings.layout.authSecurity'),
            href: editSecurity(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.project.securityNav.head')} />

            <h1 className="sr-only">
                {t('settings.project.securityNav.head')}
            </h1>

            <SettingsLayout>
                <Form
                    {...ProjectSecuritySettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="two_factor_required"
                                value={form.two_factor_required ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="password_policy"
                                value={form.password_policy}
                            />
                            <input
                                type="hidden"
                                name="oidc_enabled"
                                value={form.oidc_enabled ? '1' : '0'}
                            />
                            {SSO_TOGGLE_NAMES.map((name) => (
                                <input
                                    key={name}
                                    type="hidden"
                                    name={name}
                                    value={form[name] ? '1' : '0'}
                                />
                            ))}

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.securityTitle')}
                                    description={t(
                                        'settings.project.securityDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.password_policy
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="password_policy">
                                            {t(
                                                'settings.project.passwordPolicy',
                                            )}
                                        </FieldLabel>
                                        <Select
                                            value={form.password_policy}
                                            onValueChange={(value) => {
                                                if (
                                                    value === 'weak' ||
                                                    value === 'medium' ||
                                                    value === 'strong'
                                                ) {
                                                    setForm((current) => ({
                                                        ...current,
                                                        password_policy: value,
                                                    }));
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                id="password_policy"
                                                className="w-full"
                                                aria-invalid={
                                                    errors.password_policy
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.password_policy
                                                        ? 'password-policy-error'
                                                        : undefined
                                                }
                                            >
                                                <SelectValue>
                                                    {t(
                                                        `settings.project.passwordPolicies.${form.password_policy}.label`,
                                                    )}
                                                </SelectValue>
                                            </SelectTrigger>
                                            <SelectContent>
                                                {passwordPolicies.map(
                                                    (policy) => (
                                                        <SelectItem
                                                            key={policy}
                                                            value={policy}
                                                            textValue={t(
                                                                `settings.project.passwordPolicies.${policy}.label`,
                                                            )}
                                                            className="items-start py-2"
                                                        >
                                                            <span className="flex flex-col gap-0.5 text-left">
                                                                <span>
                                                                    {t(
                                                                        `settings.project.passwordPolicies.${policy}.label`,
                                                                    )}
                                                                </span>
                                                                <span className="text-xs font-normal whitespace-normal text-muted-foreground">
                                                                    {t(
                                                                        `settings.project.passwordPolicies.${policy}.description`,
                                                                    )}
                                                                </span>
                                                            </span>
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <ul className="list-disc space-y-1 pl-6 text-sm text-muted-foreground">
                                            {passwordPolicies.map((policy) => (
                                                <li key={`hint-${policy}`}>
                                                    <span className="font-medium text-foreground">
                                                        {t(
                                                            `settings.project.passwordPolicies.${policy}.label`,
                                                        )}
                                                        :
                                                    </span>{' '}
                                                    {t(
                                                        `settings.project.passwordPolicies.${policy}.description`,
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                        <FieldError id="password-policy-error">
                                            {errors.password_policy}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_max_attempts
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_max_attempts">
                                            {t(
                                                'settings.project.loginMaxAttempts',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="login_max_attempts"
                                            name="login_max_attempts"
                                            type="number"
                                            min={1}
                                            max={100}
                                            value={form.login_max_attempts}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_max_attempts: Number(
                                                        event.target.value,
                                                    ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_max_attempts
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.login_max_attempts
                                                    ? 'login-max-attempts-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="login-max-attempts-error">
                                            {errors.login_max_attempts}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.two_factor_required
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="two_factor_required"
                                                checked={
                                                    form.two_factor_required
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        two_factor_required:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors.two_factor_required
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.two_factor_required
                                                        ? 'two-factor-required-error'
                                                        : 'two-factor-required-hint'
                                                }
                                            />
                                            <FieldLabel htmlFor="two_factor_required">
                                                {t(
                                                    'settings.project.twoFactorRequired',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="two-factor-required-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.project.twoFactorRequiredHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="two-factor-required-error">
                                            {errors.two_factor_required}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.oidcTitle')}
                                    description={t(
                                        'settings.project.oidcDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.oidc_enabled
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="oidc_enabled"
                                                checked={form.oidc_enabled}
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        oidc_enabled:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors.oidc_enabled
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.oidc_enabled
                                                        ? 'oidc-enabled-error'
                                                        : undefined
                                                }
                                            />
                                            <FieldLabel htmlFor="oidc_enabled">
                                                {t(
                                                    'settings.project.oidcEnabled',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldError id="oidc-enabled-error">
                                            {errors.oidc_enabled}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.oidc_issuer
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="oidc_issuer">
                                            {t('settings.project.oidcIssuer')}
                                        </FieldLabel>
                                        <Input
                                            id="oidc_issuer"
                                            name="oidc_issuer"
                                            type="url"
                                            placeholder="https://idp.example.com/realms/excubia"
                                            value={form.oidc_issuer}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    oidc_issuer:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.oidc_issuer
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.oidc_issuer
                                                    ? 'oidc-issuer-error'
                                                    : 'oidc-issuer-hint'
                                            }
                                        />
                                        <FieldDescription id="oidc-issuer-hint">
                                            {t(
                                                'settings.project.oidcIssuerHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="oidc-issuer-error">
                                            {errors.oidc_issuer}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.oidc_client_id
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="oidc_client_id">
                                            {t('settings.project.oidcClientId')}
                                        </FieldLabel>
                                        <Input
                                            id="oidc_client_id"
                                            name="oidc_client_id"
                                            value={form.oidc_client_id}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    oidc_client_id:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.oidc_client_id
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.oidc_client_id
                                                    ? 'oidc-client-id-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="oidc-client-id-error">
                                            {errors.oidc_client_id}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.oidc_client_secret
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="oidc_client_secret">
                                            {t(
                                                'settings.project.oidcClientSecret',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="oidc_client_secret"
                                            name="oidc_client_secret"
                                            type="password"
                                            autoComplete="new-password"
                                            value={form.oidc_client_secret}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    oidc_client_secret:
                                                        event.target.value,
                                                }))
                                            }
                                            placeholder={
                                                project.has_oidc_client_secret
                                                    ? '••••••••'
                                                    : undefined
                                            }
                                            aria-invalid={
                                                errors.oidc_client_secret
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.oidc_client_secret
                                                    ? 'oidc-client-secret-error'
                                                    : 'oidc-client-secret-hint'
                                            }
                                        />
                                        <FieldDescription id="oidc-client-secret-hint">
                                            {project.has_oidc_client_secret
                                                ? t(
                                                      'settings.project.oidcSecretConfiguredHint',
                                                  )
                                                : t(
                                                      'settings.project.oidcSecretEmptyHint',
                                                  )}
                                        </FieldDescription>
                                        <FieldError id="oidc-client-secret-error">
                                            {errors.oidc_client_secret}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.oidc_button_label
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="oidc_button_label">
                                            {t(
                                                'settings.project.oidcButtonLabel',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="oidc_button_label"
                                            name="oidc_button_label"
                                            value={form.oidc_button_label}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    oidc_button_label:
                                                        event.target.value,
                                                }))
                                            }
                                            placeholder={t(
                                                'settings.project.oidcButtonLabelPlaceholder',
                                            )}
                                            aria-invalid={
                                                errors.oidc_button_label
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.oidc_button_label
                                                    ? 'oidc-button-label-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="oidc-button-label-error">
                                            {errors.oidc_button_label}
                                        </FieldError>
                                    </Field>

                                    {SSO_TOGGLE_NAMES.map((name) => (
                                        <SsoToggleField
                                            key={name}
                                            name={name}
                                            label={t(
                                                SSO_TOGGLE_TEXTS[name].label,
                                            )}
                                            hint={t(
                                                SSO_TOGGLE_TEXTS[name].hint,
                                            )}
                                            checked={form[name]}
                                            error={errors[name]}
                                            onCheckedChange={(checked) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    [name]: checked,
                                                }))
                                            }
                                        />
                                    ))}
                                </FieldGroup>

                                <p className="text-sm text-muted-foreground">
                                    {t('settings.project.oidcRedirectHint', {
                                        url: oidcCallbackUrl,
                                    })}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('settings.project.oidcBackchannelHint', {
                                        url: oidcBackchannelLogoutUrl,
                                    })}
                                </p>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="project-security-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
