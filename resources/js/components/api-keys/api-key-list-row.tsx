import type { ColumnDef } from '@tanstack/react-table';
import { RotateCw, Trash2 } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { Badge } from '@/components/ui/badge';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { apiKeyStatus } from '@/lib/api-key-scopes';
import type { ApiKeyStatus } from '@/lib/api-key-scopes';

/**
 * The baseline columns a key row shows (docs/api-keys.md), as the API returns them. An app
 * row type with more fields (owner, tenancy) extends it and keeps them in its own columns.
 */
export type ApiKeyListRow = {
    id: string | number;
    name: string;
    key_prefix: string;
    scopes: string[];
    expires_at: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
};

const STATUS_BADGE_VARIANTS = {
    active: 'success',
    expired: 'warning',
    revoked: 'muted',
} as const satisfies Record<ApiKeyStatus, string>;

/** Active / Expired / Revoked, in the status colours of the list pattern. */
export function ApiKeyStatusBadge({ status }: { status: ApiKeyStatus }) {
    const { t } = useTranslation();

    return (
        <Badge variant={STATUS_BADGE_VARIANTS[status]}>
            {t(`apiKeys.status.${status}`)}
        </Badge>
    );
}

export type ApiKeyRowActionsProps = {
    keyName: string;
    status: ApiKeyStatus;
    /** Omit when the product does not rotate from this screen. */
    onRotate?: () => void;
    onRevoke: () => void;
};

/**
 * The row actions of the list pattern: outline 24px icon buttons, Revoke last in the
 * destructive colour. Only an active key rotates; a revoked key has nothing left to do.
 */
export function ApiKeyRowActions({
    keyName,
    status,
    onRotate,
    onRevoke,
}: ApiKeyRowActionsProps) {
    const { t } = useTranslation();

    if (status === 'revoked') {
        return null;
    }

    return (
        <ButtonGroup aria-label={t('apiKeys.list.actions')} className="ml-auto">
            {onRotate && status === 'active' ? (
                <HeaderIconButton
                    variant="outline"
                    size="icon-sm"
                    label={t('apiKeys.list.rotate', { name: keyName })}
                    data-test="api-key-rotate"
                    onClick={onRotate}
                >
                    <RotateCw className="size-3.5" />
                </HeaderIconButton>
            ) : null}
            <HeaderIconButton
                variant="outline"
                size="icon-sm"
                className="text-destructive hover:text-destructive"
                label={t('apiKeys.list.revoke', { name: keyName })}
                data-test="api-key-revoke"
                onClick={onRevoke}
            >
                <Trash2 className="size-3.5" />
            </HeaderIconButton>
        </ButtonGroup>
    );
}

export type UseApiKeyColumnsOptions<Row extends ApiKeyListRow> = {
    onRotate?: (row: Row) => void;
    onRevoke: (row: Row) => void;
    /** The moment the status is judged against; defaults to the render time. */
    now?: Date;
};

/**
 * The `DataTable` columns of the key list: name, key prefix, permissions, last used,
 * expires, status and the row actions, in that order. The app splices its own columns in
 * (an owner before the status, say) and passes the result to `DataTable`.
 *
 * @returns `ColumnDef`s for any row type that carries the baseline columns
 */
export function useApiKeyColumns<Row extends ApiKeyListRow>({
    onRotate,
    onRevoke,
    now,
}: UseApiKeyColumnsOptions<Row>): ColumnDef<Row>[] {
    const { t, i18n } = useTranslation();

    return useMemo(() => {
        const judgedAt = now ?? new Date();
        const dateFormat = new Intl.DateTimeFormat(i18n.language, {
            dateStyle: 'medium',
        });
        const formatDate = (isoDate: string | null, emptyLabel: string) =>
            isoDate === null
                ? emptyLabel
                : dateFormat.format(new Date(isoDate));

        return [
            {
                id: 'name',
                header: t('apiKeys.list.name'),
                meta: { cellClassName: 'font-medium' },
                cell: ({ row }) => row.original.name,
            },
            {
                id: 'key_prefix',
                header: t('apiKeys.list.key'),
                meta: { cellClassName: 'font-mono text-xs' },
                cell: ({ row }) => `${row.original.key_prefix}…`,
            },
            {
                id: 'scopes',
                header: t('apiKeys.list.permissions'),
                cell: ({ row }) => {
                    const scopeList = row.original.scopes.join(', ');

                    if (scopeList === '') {
                        return (
                            <span className="text-muted-foreground">
                                {t('apiKeys.list.noPermissions')}
                            </span>
                        );
                    }

                    return (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="block max-w-64 truncate font-mono text-xs text-muted-foreground">
                                    {scopeList}
                                </span>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-sm break-words">
                                {scopeList}
                            </TooltipContent>
                        </Tooltip>
                    );
                },
            },
            {
                id: 'last_used_at',
                header: t('apiKeys.list.lastUsed'),
                meta: { cellClassName: 'text-muted-foreground' },
                cell: ({ row }) =>
                    formatDate(
                        row.original.last_used_at,
                        t('apiKeys.list.neverUsed'),
                    ),
            },
            {
                id: 'expires_at',
                header: t('apiKeys.list.expires'),
                meta: { cellClassName: 'text-muted-foreground' },
                cell: ({ row }) =>
                    formatDate(
                        row.original.expires_at,
                        t('apiKeys.list.never'),
                    ),
            },
            {
                id: 'status',
                header: t('apiKeys.list.status'),
                cell: ({ row }) => (
                    <ApiKeyStatusBadge
                        status={apiKeyStatus(row.original, judgedAt)}
                    />
                ),
            },
            {
                id: 'actions',
                header: t('apiKeys.list.actions'),
                meta: {
                    headerClassName: 'w-[1%] text-right',
                    cellClassName: 'text-right',
                    isRowClickIgnored: true,
                },
                cell: ({ row }) => (
                    <ApiKeyRowActions
                        keyName={row.original.name}
                        status={apiKeyStatus(row.original, judgedAt)}
                        onRotate={
                            onRotate ? () => onRotate(row.original) : undefined
                        }
                        onRevoke={() => onRevoke(row.original)}
                    />
                ),
            },
        ];
    }, [t, i18n.language, now, onRotate, onRevoke]);
}

export type ApiKeyRevokeDialogProps = {
    /** The name of the key about to be revoked; the dialog is open while it is set. */
    keyName: string | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    isRevoking?: boolean;
};

/**
 * The revoke confirmation (docs/destructive-actions.md): revocation is immediate and cannot
 * be undone, but the row stays for the audit trail, so a typed confirmation is not needed.
 */
export function ApiKeyRevokeDialog({
    keyName,
    onOpenChange,
    onConfirm,
    isRevoking = false,
}: ApiKeyRevokeDialogProps) {
    const { t } = useTranslation();

    return (
        <ConfirmDestructiveDialog
            open={keyName !== null}
            onOpenChange={onOpenChange}
            title={t('apiKeys.revoke.title', { name: keyName ?? '' })}
            description={t('apiKeys.revoke.description')}
            confirmLabel={t('apiKeys.revoke.confirm')}
            confirming={isRevoking}
            onConfirm={onConfirm}
        />
    );
}
