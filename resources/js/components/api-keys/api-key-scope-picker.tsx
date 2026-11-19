import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLegend,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
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
 * The permissions of an API key: one row per resource with a No access / Read / Write
 * segmented control (a radio group for assistive technology), so the vocabulary of
 * docs/api-keys.md is the only thing the screen can produce. Write includes read, so a row
 * holds one level and the value carries at most one scope per resource.
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
                    const titleId = `${pickerId}-${scopeResource.resource}`;
                    const availableActions = scopeResource.availableActions ?? [
                        'read',
                        'write',
                    ];
                    const level = apiKeyAccessLevel(
                        value,
                        scopeResource.resource,
                    );

                    return (
                        <Field
                            key={scopeResource.resource}
                            orientation="horizontal"
                            className="items-center justify-between gap-4 px-3 py-2"
                        >
                            <FieldContent>
                                <FieldTitle id={titleId}>
                                    {scopeResource.label}
                                </FieldTitle>
                                {scopeResource.description ? (
                                    <FieldDescription>
                                        {scopeResource.description}
                                    </FieldDescription>
                                ) : null}
                            </FieldContent>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={level}
                                aria-labelledby={titleId}
                                disabled={isDisabled}
                                className="shrink-0"
                                onValueChange={(nextLevel) => {
                                    // A single toggle group clears on a second click; a row always holds a level.
                                    if (nextLevel === '') {
                                        return;
                                    }

                                    onChange(
                                        withApiKeyAccessLevel(
                                            value,
                                            scopeResource.resource,
                                            nextLevel as ApiKeyAccessLevel,
                                        ),
                                    );
                                }}
                            >
                                <ToggleGroupItem value="none">
                                    {t('apiKeys.scopes.none')}
                                </ToggleGroupItem>
                                {availableActions.map((action) => (
                                    <ToggleGroupItem
                                        key={action}
                                        value={action}
                                    >
                                        {t(`apiKeys.scopes.${action}`)}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        </Field>
                    );
                })}
            </div>
            <FieldError id={errorId}>{error}</FieldError>
        </FieldSet>
    );
}
