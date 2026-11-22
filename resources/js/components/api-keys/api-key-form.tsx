import type { FormEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiKeyScopePicker } from '@/components/api-keys/api-key-scope-picker';
import type { ApiKeyScopeResource } from '@/components/api-keys/api-key-scope-picker';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * The baseline fields of a new key, keyed like the columns and the request payload
 * (docs/api-keys.md), as the strings the inputs hold. The app converts empty strings to
 * null and the rate limit to a number when it submits.
 */
export type ApiKeyFormValues = {
    name: string;
    scopes: string[];
    /** `datetime-local` value (`YYYY-MM-DDTHH:mm`), empty for a key that never expires. */
    expires_at: string;
    /** Addresses and CIDR ranges, comma or space separated; empty allows every address. */
    ip_allowlist: string;
    rate_limit_per_minute: string;
};

export type ApiKeyFormProps = {
    /** The `<form>` id, for a submit button outside it (`DialogFooter`, a drawer footer). */
    id: string;
    values: ApiKeyFormValues;
    /** Same shape as Inertia's `setData(field, value)`, so `form.setData` can be passed as is. */
    onFieldChange: <FieldName extends keyof ApiKeyFormValues>(
        field: FieldName,
        value: ApiKeyFormValues[FieldName],
    ) => void;
    onSubmit: () => void;
    /** Server validation messages, keyed like the values. */
    errors?: Partial<Record<keyof ApiKeyFormValues, string>>;
    resources: readonly ApiKeyScopeResource[];
    isProcessing?: boolean;
    /** The app's own fields (owner, tenancy, collections), after the name. */
    children?: ReactNode;
};

/**
 * The issuing form for an API key: name, permissions, then the optional restrictions
 * (expiry, IP allowlist, rate limit). It renders only the `<form>`: the app puts it in the
 * dialog, drawer or page its layout uses and owns the submit button, so the same fields
 * appear wherever the screen lives. Server errors are the only validation (`noValidate`).
 */
export function ApiKeyForm({
    id,
    values,
    onFieldChange,
    onSubmit,
    errors = {},
    resources,
    isProcessing = false,
    children,
}: ApiKeyFormProps) {
    const { t } = useTranslation();

    // Every field has a hint; its error replaces it in the description while it shows.
    const describedBy = (field: keyof ApiKeyFormValues) =>
        errors[field] ? `${id}-${field}-error` : `${id}-${field}-hint`;

    return (
        <form
            id={id}
            noValidate
            onSubmit={(event: FormEvent<HTMLFormElement>) => {
                event.preventDefault();
                onSubmit();
            }}
        >
            <FieldGroup className="gap-6">
                <p className="text-sm text-muted-foreground">
                    {t('apiKeys.form.requiredHint')}
                </p>

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor={`${id}-name`}>
                        {t('apiKeys.form.name')} *
                    </FieldLabel>
                    <Input
                        id={`${id}-name`}
                        value={values.name}
                        autoComplete="off"
                        disabled={isProcessing}
                        aria-invalid={Boolean(errors.name)}
                        aria-describedby={describedBy('name')}
                        onChange={(event) =>
                            onFieldChange('name', event.target.value)
                        }
                    />
                    <FieldDescription id={`${id}-name-hint`}>
                        {t('apiKeys.form.nameDescription')}
                    </FieldDescription>
                    <FieldError id={`${id}-name-error`}>
                        {errors.name}
                    </FieldError>
                </Field>

                {children}

                <ApiKeyScopePicker
                    resources={resources}
                    value={values.scopes}
                    error={errors.scopes}
                    isDisabled={isProcessing}
                    onChange={(scopes) => onFieldChange('scopes', scopes)}
                />

                <FieldSet className="gap-4">
                    <FieldLegend variant="label" className="mb-0">
                        {t('apiKeys.form.restrictions')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('apiKeys.form.restrictionsDescription')}
                    </FieldDescription>

                    <Field data-invalid={errors.expires_at ? true : undefined}>
                        <FieldLabel htmlFor={`${id}-expires_at`}>
                            {t('apiKeys.form.expiresAt')}
                        </FieldLabel>
                        <Input
                            id={`${id}-expires_at`}
                            type="datetime-local"
                            className="w-auto"
                            value={values.expires_at}
                            disabled={isProcessing}
                            aria-invalid={Boolean(errors.expires_at)}
                            aria-describedby={describedBy('expires_at')}
                            onChange={(event) =>
                                onFieldChange('expires_at', event.target.value)
                            }
                        />
                        <FieldDescription id={`${id}-expires_at-hint`}>
                            {t('apiKeys.form.expiresAtDescription')}
                        </FieldDescription>
                        <FieldError id={`${id}-expires_at-error`}>
                            {errors.expires_at}
                        </FieldError>
                    </Field>

                    <Field
                        data-invalid={errors.ip_allowlist ? true : undefined}
                    >
                        <FieldLabel htmlFor={`${id}-ip_allowlist`}>
                            {t('apiKeys.form.ipAllowlist')}
                        </FieldLabel>
                        <Input
                            id={`${id}-ip_allowlist`}
                            value={values.ip_allowlist}
                            autoComplete="off"
                            spellCheck={false}
                            placeholder={t(
                                'apiKeys.form.ipAllowlistPlaceholder',
                            )}
                            disabled={isProcessing}
                            aria-invalid={Boolean(errors.ip_allowlist)}
                            aria-describedby={describedBy('ip_allowlist')}
                            onChange={(event) =>
                                onFieldChange(
                                    'ip_allowlist',
                                    event.target.value,
                                )
                            }
                        />
                        <FieldDescription id={`${id}-ip_allowlist-hint`}>
                            {t('apiKeys.form.ipAllowlistDescription')}
                        </FieldDescription>
                        <FieldError id={`${id}-ip_allowlist-error`}>
                            {errors.ip_allowlist}
                        </FieldError>
                    </Field>

                    <Field
                        data-invalid={
                            errors.rate_limit_per_minute ? true : undefined
                        }
                    >
                        <FieldLabel htmlFor={`${id}-rate_limit_per_minute`}>
                            {t('apiKeys.form.rateLimit')}
                        </FieldLabel>
                        <Input
                            id={`${id}-rate_limit_per_minute`}
                            type="number"
                            inputMode="numeric"
                            min={1}
                            className="w-32"
                            value={values.rate_limit_per_minute}
                            disabled={isProcessing}
                            aria-invalid={Boolean(errors.rate_limit_per_minute)}
                            aria-describedby={describedBy(
                                'rate_limit_per_minute',
                            )}
                            onChange={(event) =>
                                onFieldChange(
                                    'rate_limit_per_minute',
                                    event.target.value,
                                )
                            }
                        />
                        <FieldDescription
                            id={`${id}-rate_limit_per_minute-hint`}
                        >
                            {t('apiKeys.form.rateLimitDescription')}
                        </FieldDescription>
                        <FieldError id={`${id}-rate_limit_per_minute-error`}>
                            {errors.rate_limit_per_minute}
                        </FieldError>
                    </Field>
                </FieldSet>
            </FieldGroup>
        </form>
    );
}
