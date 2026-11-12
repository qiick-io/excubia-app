import { Link } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { FixActions } from '@/components/reports/fix-actions';
import { SeverityBadge } from '@/components/reports/severity-badge';
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
import type { TopIssue } from '@/types/dashboard';

/** The grouped findings across projects, worst first. */
export function TopIssuesCard({
    issues,
    total,
}: {
    issues: TopIssue[];
    total: number;
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.topIssues.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.topIssues.description', {
                        shown: issues.length,
                        total,
                    })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {issues.length === 0 ? (
                    <PageEmptyState
                        icon={CircleCheck}
                        title={t('dashboard.topIssues.emptyTitle')}
                        description={t('dashboard.topIssues.emptyDescription')}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-96 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('report.severityLabel')}
                                    </TableHead>
                                    <TableHead>
                                        {t('dashboard.topIssues.issue')}
                                    </TableHead>
                                    <TableHead>
                                        {t('report.installed')}
                                    </TableHead>
                                    <TableHead>{t('report.fixedIn')}</TableHead>
                                    <TableHead>
                                        {t('dashboard.topIssues.projects')}
                                    </TableHead>
                                    <TableHead className="w-[1%] text-right">
                                        {t('fix.actions')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {issues.map((issue) => (
                                    <TableRow key={issue.key}>
                                        <TableCell>
                                            <SeverityBadge
                                                severity={issue.severity}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-[28rem] whitespace-normal">
                                            <div className="font-medium">
                                                {issue.package}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {issue.checkName ??
                                                    issue.checkId}
                                                {issue.advisoryId ? (
                                                    <span className="font-mono">
                                                        {' · '}
                                                        {issue.advisoryId}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {issue.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {issue.installed.join(', ') || '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {issue.fixedVersions.join(', ') ||
                                                '—'}
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <div className="flex flex-wrap gap-1">
                                                {issue.projects.map(
                                                    (project) => (
                                                        <Badge
                                                            key={project.slug}
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={projectsRoutes.view.url(
                                                                    {
                                                                        project:
                                                                            project.slug,
                                                                        view: 'findings',
                                                                    },
                                                                )}
                                                            >
                                                                {project.name}
                                                            </Link>
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <FixActions fix={issue.fix} />
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
