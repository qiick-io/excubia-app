import { router } from '@inertiajs/react';
import { Bug, SearchX, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import { useCopyWithToast } from '@/components/reports/fix-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type { ExceptionGroupRow } from '@/types';

const EXCEPTIONS_GUIDE_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/docs/guides/exception-capture.md';

type StatusFilter = ExceptionGroupRow['status'] | 'all';

const STATUS_VARIANTS = {
    open: 'destructive',
    resolved: 'success',
    ignored: 'muted',
} as const;

/**
 * Exceptions of a project grouped into issues (#26), as an admin list (app-ui
 * `docs/list-page.md`): search on the left, status segmented control on the right, a table, and
 * a detail drawer with the stack and the actions (resolve, ignore, reopen, copy a fix prompt).
 */
export function ExceptionsView({
    projectId,
    groups,
    canManage,
}: {
    projectId: number;
    groups: ExceptionGroupRow[];
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('open');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = groups.find((group) => group.id === selectedId) ?? null;

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return groups.filter(
            (group) =>
                (status === 'all' || group.status === status) &&
                (needle === '' ||
                    `${group.class} ${group.message} ${group.context.name}`
                        .toLowerCase()
                        .includes(needle)),
        );
    }, [groups, search, status]);

    if (groups.length === 0) {
        return (
            <PageLayout>
                <PageEmptyState
                    icon={Bug}
                    title={t('exceptions.emptyTitle')}
                    description={t('exceptions.emptyDescription')}
                    docsHref={EXCEPTIONS_GUIDE_URL}
                    docsLabel={t('exceptions.guide')}
                />
            </PageLayout>
        );
    }

    const hasActiveFilters = search.trim() !== '' || status !== 'open';

    return (
        <Drawer
            direction="right"
            open={selected !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setSelectedId(null);
                }
            }}
        >
            <PageLayout
                filters={
                    <DataTableToolbar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder={t('exceptions.search')}
                    />
                }
                filtersRight={
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={status}
                        onValueChange={(value) => {
                            if (value) {
                                setStatus(value as StatusFilter);
                            }
                        }}
                        aria-label={t('exceptions.statusFilter')}
                    >
                        {(['open', 'resolved', 'ignored', 'all'] as const).map(
                            (item) => (
                                <ToggleGroupItem
                                    key={item}
                                    value={item}
                                    className="px-2.5"
                                >
                                    {t(`exceptions.status.${item}`)}
                                </ToggleGroupItem>
                            ),
                        )}
                    </ToggleGroup>
                }
            >
                {filtered.length === 0 ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>
                                {t('exceptions.noMatchTitle')}
                            </EmptyStatus>
                        }
                        description={t('exceptions.noMatchDescription')}
                        action={
                            hasActiveFilters ? (
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        setStatus('all');
                                    }}
                                >
                                    {t('common.clearFilters')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <TablePanel>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('exceptions.exception')}
                                    </TableHead>
                                    <TableHead>
                                        {t('exceptions.context')}
                                    </TableHead>
                                    <TableHead>
                                        {t('exceptions.environment')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('exceptions.count')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('exceptions.lastSeen')}
                                    </TableHead>
                                    <TableHead>
                                        {t('exceptions.statusLabel')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.map((group) => (
                                    <TableRow
                                        key={group.id}
                                        className="cursor-pointer"
                                        onClick={() => setSelectedId(group.id)}
                                    >
                                        <TableCell className="max-w-[32rem]">
                                            <div className="truncate font-mono text-xs font-medium">
                                                {group.class}
                                            </div>
                                            <div className="truncate text-sm text-muted-foreground">
                                                {group.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="max-w-[14rem]">
                                            <div className="truncate text-sm">
                                                {group.context.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {t(
                                                    `exceptions.kind.${group.context.kind}`,
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {group.environment}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {group.count.toLocaleString()}
                                        </TableCell>
                                        <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                                            {new Date(
                                                group.last_seen,
                                            ).toLocaleString()}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    STATUS_VARIANTS[
                                                        group.status
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `exceptions.status.${group.status}`,
                                                )}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TablePanel>
                )}
            </PageLayout>

            {selected ? (
                <ExceptionDrawer
                    projectId={projectId}
                    group={selected}
                    canManage={canManage}
                />
            ) : null}
        </Drawer>
    );
}

/**
 * One exception group: masked message, chain, stack (project frames emphasised), timing, and
 * the actions.
 */
function ExceptionDrawer({
    projectId,
    group,
    canManage,
}: {
    projectId: number;
    group: ExceptionGroupRow;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const copyWithToast = useCopyWithToast();

    const setStatus = (status: ExceptionGroupRow['status']): void => {
        router.patch(
            projectsRoutes.exceptions.update.url({
                project: projectId,
                exceptionGroup: group.id,
            }),
            { status },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <DrawerContent className="data-[vaul-drawer-direction=right]:max-w-2xl">
            <DrawerHeader>
                <DrawerTitle className="font-mono text-sm break-all">
                    {group.class}
                </DrawerTitle>
                <DrawerDescription>
                    {t('exceptions.summary', {
                        count: group.count,
                        environment: group.environment,
                        first: new Date(group.first_seen).toLocaleString(),
                        last: new Date(group.last_seen).toLocaleString(),
                    })}
                </DrawerDescription>
            </DrawerHeader>
            <DrawerBody className="gap-4">
                <section className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('exceptions.message')}
                    </h3>
                    <p className="rounded-md bg-muted p-3 font-mono text-xs break-words whitespace-pre-wrap">
                        {group.message || '—'}
                    </p>
                </section>
                <section className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <span>
                        <span className="text-muted-foreground">
                            {t(`exceptions.kind.${group.context.kind}`)}:
                        </span>{' '}
                        {group.context.name}
                    </span>
                    {group.previous.length > 0 ? (
                        <span>
                            <span className="text-muted-foreground">
                                {t('exceptions.causedBy')}:
                            </span>{' '}
                            <span className="font-mono text-xs">
                                {group.previous.join(' ← ')}
                            </span>
                        </span>
                    ) : null}
                </section>
                <section className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('exceptions.stack')}
                    </h3>
                    <ol className="divide-y rounded-md border">
                        {group.frames.map((frame, index) => (
                            <li
                                key={index}
                                className={cn(
                                    'px-3 py-1.5 font-mono text-xs break-all',
                                    frame.inApp
                                        ? 'bg-accent/40 text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <div>
                                    {frame.file ?? t('exceptions.internal')}
                                    {frame.line !== null
                                        ? `:${frame.line}`
                                        : ''}
                                </div>
                                {frame.function ? (
                                    <div className="opacity-80">
                                        {frame.function}
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                    <p className="text-xs text-muted-foreground">
                        {t('exceptions.stackHint')}
                    </p>
                </section>
            </DrawerBody>
            <DrawerFooter className="flex flex-row flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="text-ai-text"
                    onClick={() => void copyWithToast(group.fix.prompt)}
                >
                    <Sparkles className="size-4" />
                    {t('fix.copyPrompt')}
                </Button>
                {canManage && group.status !== 'ignored' ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setStatus('ignored')}
                    >
                        {t('exceptions.ignore')}
                    </Button>
                ) : null}
                {canManage && group.status !== 'open' ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setStatus('open')}
                    >
                        {t('exceptions.reopen')}
                    </Button>
                ) : null}
                {canManage && group.status !== 'resolved' ? (
                    <Button type="button" onClick={() => setStatus('resolved')}>
                        {t('exceptions.resolve')}
                    </Button>
                ) : null}
            </DrawerFooter>
        </DrawerContent>
    );
}
