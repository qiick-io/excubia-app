import { Link } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { LICENSE_KIND_VARIANTS } from '@/components/reports/licenses-view';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import projectsRoutes from '@/routes/projects';
import type { LicenseRow } from '@/types/dashboard';

/**
 * Fleet licenses (#108): which projects use a denied, strong copyleft or unknown license, and
 * through which packages. Empty when no latest report flags one.
 */
export function LicensesCard({ rows }: { rows: LicenseRow[] }) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('licenses.fleetTitle')}</CardTitle>
                <CardDescription>
                    {t('licenses.fleetDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <PageEmptyState
                        icon={Scale}
                        title={t('licenses.noneFlaggedTitle')}
                        description={t('licenses.noneFlagged')}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('licenses.license')}
                                    </TableHead>
                                    <TableHead>{t('licenses.kind')}</TableHead>
                                    <TableHead>
                                        {t('dashboard.projects.title')}
                                    </TableHead>
                                    <TableHead>
                                        {t('details.package')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow
                                        key={`${row.kind}-${row.license}`}
                                    >
                                        <TableCell className="font-mono text-xs">
                                            {row.license}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    LICENSE_KIND_VARIANTS[
                                                        row.kind
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `licenses.kinds.${row.kind}`,
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <div className="flex flex-wrap gap-x-2">
                                                {row.projects.map((project) => (
                                                    <Link
                                                        key={project.slug}
                                                        href={projectsRoutes.view.url(
                                                            {
                                                                project:
                                                                    project.slug,
                                                                view: 'licenses',
                                                            },
                                                        )}
                                                        className="text-sm underline-offset-4 hover:underline"
                                                    >
                                                        {project.name}
                                                    </Link>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {row.packages.join(', ')}
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
