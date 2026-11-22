import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ApiKeyForm } from '@/components/api-keys/api-key-form';
import type { ApiKeyFormValues } from '@/components/api-keys/api-key-form';
import {
    ApiKeyRevokeDialog,
    ApiKeyRowActions,
    useApiKeyColumns,
} from '@/components/api-keys/api-key-list-row';
import type { ApiKeyListRow } from '@/components/api-keys/api-key-list-row';
import type { ApiKeyScopeResource } from '@/components/api-keys/api-key-scope-picker';
import { ApiKeySecretDialog } from '@/components/api-keys/api-key-secret-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { apiKeyStatus } from '@/lib/api-key-scopes';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import apiKeysRoutes from '@/routes/api-keys';
import { edit as editProfile } from '@/routes/profile';
import type { BreadcrumbItem, Paginated } from '@/types';

type ApiKeyRow = ApiKeyListRow & {
    id: number;
    previous_key_valid_until: string | null;
};

/** A new key reads by default; writing is an explicit choice (#320). */
const DEFAULT_SCOPES = ['mcp:read'];

const EMPTY_FORM: ApiKeyFormValues = {
    name: '',
    scopes: DEFAULT_SCOPES,
    expires_at: '',
    ip_allowlist: '',
    rate_limit_per_minute: '',
};

/**
 * Expiry input uses the browser timezone: `datetime-local` has no IANA zone control.
 * Displayed times (rotation grace) use the profile timezone (`auth.user.timezone`).
 * Send ISO 8601 with the browser's offset so a 09:30 pick stays 09:30 in that zone.
 */
function datetimeLocalToIsoWithOffset(value: string): string {
    const localDate = new Date(value);
    const offsetMinutes = -localDate.getTimezoneOffset();
    const sign = offsetMinutes >= 0 ? '+' : '-';
    const absoluteMinutes = Math.abs(offsetMinutes);
    const offsetHours = String(Math.floor(absoluteMinutes / 60)).padStart(
        2,
        '0',
    );
    const offsetMins = String(absoluteMinutes % 60).padStart(2, '0');
    const pad = (part: number): string => String(part).padStart(2, '0');

    return (
        `${localDate.getFullYear()}-${pad(localDate.getMonth() + 1)}-${pad(localDate.getDate())}` +
        `T${pad(localDate.getHours())}:${pad(localDate.getMinutes())}:${pad(localDate.getSeconds())}` +
        `${sign}${offsetHours}:${offsetMins}`
    );
}

/** The request payload: empty restrictions become null, the allowlist a list of addresses. */
function toApiKeyPayload(values: ApiKeyFormValues) {
    const ipAddresses = values.ip_allowlist
        .split(/[\s,]+/)
        .filter((ipAddress) => ipAddress !== '');

    return {
        name: values.name,
        scopes: values.scopes,
        expires_at:
            values.expires_at === ''
                ? null
                : datetimeLocalToIsoWithOffset(values.expires_at),
        ip_allowlist: ipAddresses.length === 0 ? null : ipAddresses,
        rate_limit_per_minute:
            values.rate_limit_per_minute === ''
                ? null
                : Number(values.rate_limit_per_minute),
    };
}

type IssuedApiKey = {
    secret: string;
    name: string;
    reason: 'created' | 'rotated';
    previous_key_valid_until: string | null;
};

/**
 * Settings → API keys (#315, #364), built from the app-ui api-key items (docs/api-keys.md):
 * the signed-in user's own keys, since a key acts as its owner and there is no owner to pick.
 * A new or rotated secret arrives once as Inertia flash (`issuedApiKey`, stripped from
 * history.state); React copies it into local state and drops it on acknowledge.
 */
export default function ApiKeysSettingsPage({
    apiKeys: apiKeysProp,
    scopes,
}: {
    apiKeys: LaravelPaginated<ApiKeyRow> | Paginated<ApiKeyRow>;
    /** The scopes the signed-in user may grant (`ApiKeyScopeEnum::scopesCreatorMayGrant`). */
    scopes: string[];
}) {
    const { t, i18n } = useTranslation();
    const { auth } = usePage().props;
    const { flash } = usePage();
    const userTimezone = auth.user?.timezone ?? 'UTC';
    const apiKeys = normalizePaginated(apiKeysProp);
    const rows = apiKeys.data;
    const [isCreateOpen, setCreateOpen] = useState(false);
    const [issuedApiKey, setIssuedApiKey] = useState<IssuedApiKey | null>(null);
    const [pendingRevokeKey, setPendingRevokeKey] = useState<ApiKeyRow | null>(
        null,
    );
    const [isRevoking, setRevoking] = useState(false);
    const form = useForm<ApiKeyFormValues>(EMPTY_FORM);

    useEffect(() => {
        if (flash.issuedApiKey) {
            setIssuedApiKey(flash.issuedApiKey);
        }
    }, [flash.issuedApiKey]);

    const secret = issuedApiKey?.secret ?? null;

    const canRotateRow = (row: ApiKeyRow): boolean =>
        row.scopes.every((scope) => scopes.includes(scope));

    const resources: ApiKeyScopeResource[] = useMemo(
        () => [
            {
                resource: 'mcp',
                label: t('settings.apiKeys.resources.mcp.label'),
                description: t('settings.apiKeys.resources.mcp.description'),
                availableActions: (['read', 'write'] as const).filter(
                    (action) => scopes.includes(`mcp:${action}`),
                ),
            },
        ],
        [t, scopes],
    );

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('settings.layout.title'), href: editProfile() },
            {
                title: t('settings.layout.apiKeys'),
                href: apiKeysRoutes.index.url(),
            },
        ],
        [t],
    );

    const openCreate = (): void => {
        form.setData(EMPTY_FORM);
        form.clearErrors();
        setCreateOpen(true);
    };

    const submit = (): void => {
        form.transform(toApiKeyPayload);
        form.post(apiKeysRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setCreateOpen(false);
            },
        });
    };

    const rotate = (row: ApiKeyRow): void => {
        router.post(
            apiKeysRoutes.rotate.url(row.id),
            {},
            { preserveScroll: true },
        );
    };

    const columns = useApiKeyColumns<ApiKeyRow>({
        onRotate: rotate,
        onRevoke: setPendingRevokeKey,
    }).map((column) => {
        if (column.id !== 'actions') {
            return column;
        }

        return {
            ...column,
            cell: ({ row }: { row: { original: ApiKeyRow } }) => (
                <ApiKeyRowActions
                    keyName={row.original.name}
                    status={apiKeyStatus(row.original, new Date())}
                    onRotate={
                        canRotateRow(row.original)
                            ? () => rotate(row.original)
                            : undefined
                    }
                    onRevoke={() => setPendingRevokeKey(row.original)}
                />
            ),
        };
    });

    // A rejected address comes back per entry (`ip_allowlist.0`); the form shows it on the field.
    const ipAllowlistError = Object.entries(form.errors).find(([field]) =>
        field.startsWith('ip_allowlist'),
    )?.[1];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                <Button
                    type="button"
                    data-test="api-key-create-open"
                    onClick={openCreate}
                >
                    <KeyRound className="mr-1 size-4" />
                    {t('settings.apiKeys.create')}
                </Button>
            }
        >
            <Head title={t('settings.layout.apiKeys')} />

            <SettingsLayout wide>
                <div className="flex w-full flex-col gap-6">
                    <Heading
                        variant="small"
                        title={t('settings.layout.apiKeys')}
                        description={t('settings.apiKeys.description')}
                    />

                    {rows.length === 0 ? (
                        <PageEmptyState
                            icon={KeyRound}
                            title={t('settings.apiKeys.empty.title')}
                            description={t(
                                'settings.apiKeys.empty.description',
                            )}
                            action={
                                <Button type="button" onClick={openCreate}>
                                    <KeyRound className="size-4" />
                                    {t('settings.apiKeys.create')}
                                </Button>
                            }
                            compact
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={rows}
                            getRowId={(key) => String(key.id)}
                            pagination={apiKeys}
                        />
                    )}
                </div>
            </SettingsLayout>

            <Dialog
                open={isCreateOpen}
                onOpenChange={(open) => {
                    if (!open && form.processing) {
                        return;
                    }

                    setCreateOpen(open);
                }}
            >
                <DialogContent
                    className="sm:max-w-2xl"
                    data-test="api-key-create-dialog"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('settings.apiKeys.createTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('settings.apiKeys.createDescription')}
                        </DialogDescription>
                    </DialogHeader>

                    <ApiKeyForm
                        id="api-key-create-form"
                        values={form.data}
                        onFieldChange={form.setData}
                        errors={{
                            ...form.errors,
                            ip_allowlist: ipAllowlistError,
                        }}
                        resources={resources}
                        isProcessing={form.processing}
                        onSubmit={submit}
                    />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => setCreateOpen(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            form="api-key-create-form"
                            disabled={form.processing}
                            data-test="api-key-create-submit"
                        >
                            <KeyRound className="mr-1 size-4" />
                            {t('settings.apiKeys.create')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ApiKeySecretDialog
                secret={secret}
                keyName={issuedApiKey?.name ?? ''}
                reason={issuedApiKey?.reason ?? 'created'}
                previousSecretValidUntil={
                    issuedApiKey?.previous_key_valid_until
                        ? new Date(
                              issuedApiKey.previous_key_valid_until,
                          ).toLocaleString(i18n.language, {
                              timeZone: userTimezone,
                          })
                        : undefined
                }
                onAcknowledge={() => {
                    setIssuedApiKey(null);
                    router.reload({ only: ['apiKeys'] });
                }}
            />

            <ApiKeyRevokeDialog
                keyName={pendingRevokeKey?.name ?? null}
                isRevoking={isRevoking}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingRevokeKey(null);
                    }
                }}
                onConfirm={() => {
                    if (pendingRevokeKey === null) {
                        return;
                    }

                    setRevoking(true);
                    router.delete(
                        apiKeysRoutes.destroy.url(pendingRevokeKey.id),
                        {
                            preserveScroll: true,
                            onFinish: () => {
                                setRevoking(false);
                                setPendingRevokeKey(null);
                            },
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
