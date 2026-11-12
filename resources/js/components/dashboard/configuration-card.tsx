import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { FixActions } from '@/components/reports/fix-actions';
import { SeverityBadge } from '@/components/reports/severity-badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type { ConfigurationRow } from '@/types/dashboard';

/**
 * Dashboard card of the configuration findings across the scoped projects, each
 * linking to its project, or an empty state when there are none.
 */
export function ConfigurationCard({
    rows,
    className,
}: {
    rows: ConfigurationRow[];
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={cn('xl:col-span-5', className)}>
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.configuration.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.configuration.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <PageEmptyState
                        icon={ShieldCheck}
                        title={t('dashboard.configuration.emptyTitle')}
                        description={t(
                            'dashboard.configuration.emptyDescription',
                        )}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <Table>
                            <TableBody>
                                {rows.map((row, index) => (
                                    <TableRow
                                        key={`${row.project.slug}-${row.checkId}-${index}`}
                                    >
                                        <TableCell>
                                            <SeverityBadge
                                                severity={row.severity}
                                            />
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <div className="font-medium">
                                                {row.checkName ?? row.checkId}
                                            </div>
                                            <div className="text-sm">
                                                {row.message}
                                            </div>
                                            <Link
                                                href={projectsRoutes.view.url({
                                                    project: row.project.slug,
                                                    view: 'findings',
                                                })}
                                                className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                                            >
                                                {row.project.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <FixActions fix={row.fix} />
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
