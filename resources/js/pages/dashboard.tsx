import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    SeverityBadge,
    SeverityCounts,
} from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import projectsRoutes from '@/routes/projects';
import type { ProjectRow } from '@/types';

/**
 * Projects overview: one card per project with the worst severity and the counts of its
 * latest report and when it arrived. Stale projects are greyed out.
 */
export default function Dashboard({
    projects,
    canViewProjects,
}: {
    projects: ProjectRow[];
    canViewProjects: boolean;
}) {
    const { t } = useTranslation();
    const { can } = useCan();

    return (
        <>
            <Head title={t('nav.dashboard')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {!canViewProjects ? (
                    <p className="text-sm text-muted-foreground">
                        {t('dashboard.noAccess')}
                    </p>
                ) : projects.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-8 text-center">
                        <p className="font-medium">
                            {t('dashboard.emptyTitle')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('dashboard.emptyDescription')}
                        </p>
                        {can(PermissionEnum.ProjectsManage) ? (
                            <Link
                                href={projectsRoutes.index.url()}
                                className="mt-4 inline-block text-sm font-medium text-primary underline-offset-4 hover:underline"
                            >
                                {t('projects.newProject')}
                            </Link>
                        ) : null}
                    </div>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <ProjectCard project={project} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

/**
 * One project on the overview. The whole card is a link to the project page.
 */
function ProjectCard({ project }: { project: ProjectRow }) {
    const { t } = useTranslation();
    const report = project.latest_report ?? null;

    return (
        <Link
            href={projectsRoutes.show.url(project.slug)}
            className="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <Card
                className={cn(
                    'h-full transition-colors hover:bg-accent/40',
                    project.is_stale && 'opacity-60',
                )}
            >
                <CardHeader>
                    <div className="flex items-start justify-between gap-2">
                        <CardTitle className="truncate">
                            {project.name}
                        </CardTitle>
                        {report ? (
                            <SeverityBadge severity={report.worst_severity} />
                        ) : null}
                    </div>
                    <CardDescription>
                        {report
                            ? t('dashboard.lastReport', {
                                  date: new Date(
                                      report.received_at,
                                  ).toLocaleString(),
                              })
                            : t('dashboard.noReports')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-wrap items-center gap-1">
                    {report ? <SeverityCounts counts={report.counts} /> : null}
                    {project.is_stale ? (
                        <Badge variant="muted">{t('dashboard.stale')}</Badge>
                    ) : null}
                    {!project.is_enabled ? (
                        <Badge variant="outline">
                            {t('projects.disabled')}
                        </Badge>
                    ) : null}
                </CardContent>
            </Card>
        </Link>
    );
}

Dashboard.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Dashboard', href: dashboard() }]}>
        {page}
    </AppLayout>
);
