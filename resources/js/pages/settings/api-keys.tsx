import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Check, Copy, KeyRound, Trash2, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { relativeTime } from '@/lib/report-format';
import apiKeysRoutes from '@/routes/api-keys';
import { edit as editProfile } from '@/routes/profile';
import type { BreadcrumbItem, Paginated } from '@/types';

type ApiKeyRow = {
    id: number;
    name: string;
    key_prefix: string;
    scopes: string[];
    is_active: boolean;
    expires_at: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
};

/** What each scope allows, by scope value (i18next reads `:` as a namespace separator). */
const SCOPE_HELP_KEYS: Record<string, string> = {
    'mcp:read': 'settings.apiKeys.scopeHelp.mcpRead',
    'mcp:write': 'settings.apiKeys.scopeHelp.mcpWrite',
};

/** Scopes a new key starts without: writing is an explicit choice (#320). */
const OPT_IN_SCOPES = ['mcp:write'];

/**
 * Settings → API keys (#315), copied from externa-core's API keys page and narrowed to the
 * signed-in user's own keys: a key acts as its owner, so there is no role or owner to pick. A new
 * key comes back once as the `apiKey` flash and lives only in this page's state.
 */
export default function ApiKeysSettingsPage({
    apiKeys: apiKeysProp,
    scopes,
}: {
    apiKeys: LaravelPaginated<ApiKeyRow> | Paginated<ApiKeyRow>;
    scopes: string[];
}) {
    const { t, i18n } = useTranslation();
    const { flash } = usePage();
    const apiKeys = normalizePaginated(apiKeysProp);
    const rows = apiKeys.data;
    const [plainKey, setPlainKey] = useState<string | null>(null);
    const [copiedText, copy] = useClipboard();
    const [isCreateOpen, setCreateOpen] = useState(false);
    const [pendingRevokeKeyId, setPendingRevokeKeyId] = useState<number | null>(
        null,
    );
    const [isRevoking, setRevoking] = useState(false);
    const pendingRevokeKey = rows.find((key) => key.id === pendingRevokeKeyId);

    useEffect(() => {
        if (flash.apiKey) {
            setPlainKey(flash.apiKey);
        }
    }, [flash.apiKey]);

    const form = useForm<{
        name: string;
        scopes: string[];
        expires_at: string;
    }>({
        name: '',
        scopes: scopes.filter((scope) => !OPT_IN_SCOPES.includes(scope)),
        expires_at: '',
    });

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
        form.reset();
        form.clearErrors();
        setCreateOpen(true);
    };

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        form.post(apiKeysRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => setCreateOpen(false),
        });
    };

    const toggleScope = (scope: string, isChecked: boolean): void => {
        form.setData(
            'scopes',
            isChecked
                ? [...form.data.scopes.filter((item) => item !== scope), scope]
                : form.data.scopes.filter((item) => item !== scope),
        );
    };

    const columns: ColumnDef<ApiKeyRow>[] = [
        {
            id: 'name',
            header: t('settings.apiKeys.columns.name'),
            meta: { cellClassName: 'font-medium' },
            cell: ({ row }) => row.original.name,
        },
        {
            id: 'prefix',
            header: t('settings.apiKeys.columns.prefix'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row }) => `${row.original.key_prefix}…`,
        },
        {
            id: 'scopes',
            header: t('settings.apiKeys.columns.scopes'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row }) => row.original.scopes.join(', '),
        },
        {
            id: 'lastUsed',
            header: t('settings.apiKeys.columns.lastUsed'),
            meta: { cellClassName: 'text-muted-foreground' },
            cell: ({ row }) =>
                row.original.last_used_at
                    ? relativeTime(row.original.last_used_at, i18n.language)
                    : t('settings.apiKeys.never'),
        },
        {
            id: 'expires',
            header: t('settings.apiKeys.columns.expires'),
            meta: { cellClassName: 'text-muted-foreground' },
            cell: ({ row }) =>
                row.original.expires_at
                    ? new Date(row.original.expires_at).toLocaleDateString(
                          i18n.language,
                      )
                    : t('settings.apiKeys.never'),
        },
        {
            id: 'status',
            header: t('settings.apiKeys.columns.status'),
            cell: ({ row }) => {
                if (row.original.revoked_at) {
                    return (
                        <Badge variant="muted">
                            {t('settings.apiKeys.status.revoked')}
                        </Badge>
                    );
                }

                return row.original.is_active ? (
                    <Badge variant="success">
                        {t('settings.apiKeys.status.active')}
                    </Badge>
                ) : (
                    <Badge variant="muted">
                        {t('settings.apiKeys.status.expired')}
                    </Badge>
                );
            },
        },
        {
            id: 'actions',
            header: t('settings.apiKeys.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
            },
            cell: ({ row }) =>
                row.original.revoked_at ? null : (
                    <HeaderIconButton
                        type="button"
                        variant="destructive-ghost"
                        size="icon-sm"
                        label={t('settings.apiKeys.revoke')}
                        data-test="api-key-revoke"
                        onClick={() => setPendingRevokeKeyId(row.original.id)}
                    >
                        <Trash2 className="size-3.5" />
                    </HeaderIconButton>
                ),
        },
    ];

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

                    {plainKey ? (
                        <Alert variant="warning" data-test="api-key-secret">
                            <TriangleAlert />
                            <AlertTitle>
                                {t('settings.apiKeys.secretBanner')}
                            </AlertTitle>
                            <AlertDescription>
                                <InputGroup className="mt-1 w-full bg-background">
                                    <InputGroupInput
                                        readOnly
                                        data-test="api-key-secret-value"
                                        aria-label={t(
                                            'settings.apiKeys.secretValue',
                                        )}
                                        value={plainKey}
                                        className="font-mono text-foreground"
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            size="icon-xs"
                                            aria-label={
                                                copiedText === plainKey
                                                    ? t(
                                                          'settings.apiKeys.copied',
                                                      )
                                                    : t('settings.apiKeys.copy')
                                            }
                                            onClick={() => void copy(plainKey)}
                                        >
                                            {copiedText === plainKey ? (
                                                <Check />
                                            ) : (
                                                <Copy />
                                            )}
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                </InputGroup>
                            </AlertDescription>
                        </Alert>
                    ) : null}

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
                <DialogContent data-test="api-key-create-dialog">
                    <DialogHeader>
                        <DialogTitle>
                            {t('settings.apiKeys.createTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('settings.apiKeys.createDescription')}
                        </DialogDescription>
                    </DialogHeader>

                    <form id="api-key-create-form" onSubmit={submit} noValidate>
                        <FieldGroup className="gap-4">
                            <Field
                                data-invalid={
                                    form.errors.name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="api_key_name">
                                    {t('settings.apiKeys.columns.name')}
                                </FieldLabel>
                                <Input
                                    id="api_key_name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    required
                                    aria-invalid={
                                        form.errors.name ? true : undefined
                                    }
                                    aria-describedby={
                                        form.errors.name
                                            ? 'api-key-name-error'
                                            : undefined
                                    }
                                />
                                <FieldError id="api-key-name-error">
                                    {form.errors.name}
                                </FieldError>
                            </Field>

                            <FieldSet
                                data-invalid={
                                    form.errors.scopes ? true : undefined
                                }
                            >
                                <FieldLegend variant="label">
                                    {t('settings.apiKeys.columns.scopes')}
                                </FieldLegend>
                                {scopes.map((scope) => (
                                    <Field key={scope} orientation="horizontal">
                                        <Checkbox
                                            id={`api-key-scope-${scope}`}
                                            checked={form.data.scopes.includes(
                                                scope,
                                            )}
                                            onCheckedChange={(value) =>
                                                toggleScope(
                                                    scope,
                                                    value === true,
                                                )
                                            }
                                        />
                                        <FieldContent>
                                            <FieldLabel
                                                htmlFor={`api-key-scope-${scope}`}
                                                className="font-mono"
                                            >
                                                {scope}
                                            </FieldLabel>
                                            <FieldDescription>
                                                {t(
                                                    SCOPE_HELP_KEYS[scope] ??
                                                        '',
                                                )}
                                            </FieldDescription>
                                        </FieldContent>
                                    </Field>
                                ))}
                                <FieldError>{form.errors.scopes}</FieldError>
                            </FieldSet>

                            <Field
                                data-invalid={
                                    form.errors.expires_at ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="api_key_expires_at">
                                    {t('settings.apiKeys.expiresAt')}
                                </FieldLabel>
                                <Input
                                    id="api_key_expires_at"
                                    type="date"
                                    value={form.data.expires_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'expires_at',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        form.errors.expires_at
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={
                                        form.errors.expires_at
                                            ? 'api-key-expires-at-error'
                                            : 'api-key-expires-at-hint'
                                    }
                                    className="w-auto"
                                />
                                <FieldDescription id="api-key-expires-at-hint">
                                    {t('settings.apiKeys.expiresAtHelp')}
                                </FieldDescription>
                                <FieldError id="api-key-expires-at-error">
                                    {form.errors.expires_at}
                                </FieldError>
                            </Field>
                        </FieldGroup>
                    </form>

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

            <ConfirmDestructiveDialog
                open={pendingRevokeKeyId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingRevokeKeyId(null);
                    }
                }}
                title={t('settings.apiKeys.confirm.revokeTitle', {
                    name: pendingRevokeKey?.name ?? '',
                })}
                description={t('settings.apiKeys.confirm.revokeDescription')}
                confirmLabel={t('settings.apiKeys.confirm.revokeConfirm')}
                confirming={isRevoking}
                onConfirm={() => {
                    if (pendingRevokeKeyId === null) {
                        return;
                    }

                    setRevoking(true);
                    router.delete(
                        apiKeysRoutes.destroy.url(pendingRevokeKeyId),
                        {
                            preserveScroll: true,
                            onFinish: () => setRevoking(false),
                            onSuccess: () => setPendingRevokeKeyId(null),
                            onError: () => setPendingRevokeKeyId(null),
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
