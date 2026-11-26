import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { apiKeyAccessLevel, withApiKeyAccessLevel } from '@/lib/api-key-scopes';
import type {
    ApiKeyAccessLevel,
    ApiKeyScopeAction,
} from '@/lib/api-key-scopes';

/** One resource a key can be given access to, as the app names and describes it. */
export type ApiKeyScopeResource = {
    /** The resource half of the scope (`posts` in `posts:read`). */
    resource: string;
    label: string;
    description?: string;
    /** The actions the product offers on it; a resource that cannot be written passes `['read']`. */
    availableActions?: readonly ApiKeyScopeAction[];
};

export type ApiKeyScopePickerProps = {
    resources: readonly ApiKeyScopeResource[];
    /** The selected scopes, `<resource>:(read|write)`, one per resource. */
    value: readonly string[];
    onChange: (scopes: string[]) => void;
    /** The server's validation message for the scopes. */
    error?: string;
    isDisabled?: boolean;
};

/**
 * Each resource has a labelled native select so the browser handles the menu and keyboard
 * focus inside dialogs. Write includes read, so the value carries at most one scope per resource.
 */
export function ApiKeyScopePicker({
    resources,
    value,
    onChange,
    error,
    isDisabled = false,
}: ApiKeyScopePickerProps) {
    const { t } = useTranslation();
    const pickerId = useId();
    const errorId = `${pickerId}-error`;

    return (
        <FieldSet
            className="gap-3"
            data-invalid={error ? true : undefined}
            aria-describedby={error ? errorId : undefined}
        >
            <FieldLegend variant="label" className="mb-0">
                {t('apiKeys.scopes.legend')} *
            </FieldLegend>
            <FieldDescription>
                {t('apiKeys.scopes.description')}
            </FieldDescription>
            <div className="flex flex-col divide-y rounded-md border">
                {resources.map((scopeResource) => {
                    const selectId = `${pickerId}-${scopeResource.resource}`;
                    const descriptionId = `${selectId}-description`;
                    const availableActions = scopeResource.availableActions ?? [
                        'read',
                        'write',
                    ];
                    const hasActions = availableActions.length > 0;
                    const level = apiKeyAccessLevel(
                        value,
                        scopeResource.resource,
                    );
                    // Keep a stale level visible until the user changes or clears it.
                    const isLevelUnavailable =
                        level !== 'none' && !availableActions.includes(level);
                    const description = isLevelUnavailable
                        ? t('apiKeys.scopes.unavailableLevel')
                        : hasActions
                          ? scopeResource.description
                          : t('apiKeys.scopes.unavailable');

                    return (
                        <Field
                            key={scopeResource.resource}
                            orientation="horizontal"
                            className="items-center justify-between gap-4 px-3 py-2"
                        >
                            <FieldContent>
                                <FieldLabel htmlFor={selectId}>
                                    {scopeResource.label}
                                </FieldLabel>
                                {description ? (
                                    <FieldDescription id={descriptionId}>
                                        {description}
                                    </FieldDescription>
                                ) : null}
                            </FieldContent>
                            <NativeSelect
                                id={selectId}
                                value={level}
                                aria-describedby={
                                    description ? descriptionId : undefined
                                }
                                disabled={
                                    isDisabled ||
                                    (!hasActions && !isLevelUnavailable)
                                }
                                onChange={(event) =>
                                    onChange(
                                        withApiKeyAccessLevel(
                                            value,
                                            scopeResource.resource,
                                            event.currentTarget
                                                .value as ApiKeyAccessLevel,
                                        ),
                                    )
                                }
                            >
                                <NativeSelectOption value="none">
                                    {t('apiKeys.scopes.none')}
                                </NativeSelectOption>
                                {isLevelUnavailable ? (
                                    <NativeSelectOption value={level} disabled>
                                        {t(`apiKeys.scopes.${level}`)}
                                    </NativeSelectOption>
                                ) : null}
                                {availableActions.map((action) => (
                                    <NativeSelectOption
                                        key={action}
                                        value={action}
                                    >
                                        {t(`apiKeys.scopes.${action}`)}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </Field>
                    );
                })}
            </div>
            <FieldError id={errorId}>{error}</FieldError>
        </FieldSet>
    );
}
