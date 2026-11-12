import { Link } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { RuntimeSupportBadge } from '@/components/reports/runtime-support';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import projectsRoutes from '@/routes/projects';
import type { EndOfSupportRow } from '@/types/dashboard';

/**
 * Dashboard card of the runtimes whose security support has ended or ends soon, per project,
 * or an empty state when every runtime is supported.
 */
export function EndOfSupportCard({ rows }: { rows: EndOfSupportRow[] }) {
    const { t } = useTranslation();

    return (
        <Card className="xl:col-span-4">
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.endOfSupport.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.endOfSupport.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <PageEmptyState
                        icon={CalendarClock}
                        title={t('dashboard.endOfSupport.emptyTitle')}
                        description={t(
                            'dashboard.endOfSupport.emptyDescription',
                        )}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-64 overflow-auto">
                        <Table>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow
                                        key={`${row.project.slug}-${row.runtime}`}
                                    >
                                        <TableCell className="min-w-0">
                                            <div className="truncate font-medium">
                                                {t(
                                                    `report.runtime.${row.runtime}`,
                                                )}{' '}
                                                <span className="font-mono text-xs">
                                                    {row.version ?? row.cycle}
                                                </span>
                                            </div>
                                            <Link
                                                href={projectsRoutes.show.url(
                                                    row.project.slug,
                                                )}
                                                className="truncate text-xs text-muted-foreground underline-offset-4 hover:underline"
                                            >
                                                {row.project.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="text-xs tabular-nums">
                                                {row.securitySupportEndsOn}
                                            </div>
                                            <RuntimeSupportBadge
                                                runtime={{
                                                    version: row.version,
                                                    cycle: row.cycle,
                                                    security_support_ends_on:
                                                        row.securitySupportEndsOn,
                                                    active_support_ends_on:
                                                        row.activeSupportEndsOn,
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
