import { Head, Link } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import {
    SeverityBadge,
    SeverityCounts,
} from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
            <PageLayout scrollContent>
                {!canViewProjects ? (
                    <p className="text-sm text-muted-foreground">
                        {t('dashboard.noAccess')}
                    </p>
                ) : projects.length === 0 ? (
                    <PageEmptyState
                        icon={FolderKanban}
                        title={t('dashboard.emptyTitle')}
                        description={t('dashboard.emptyDescription')}
                        action={
                            can(PermissionEnum.ProjectsManage) ? (
                                <Button asChild>
                                    <Link href={projectsRoutes.index.url()}>
                                        <Plus className="size-4" />
                                        {t('projects.newProject')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ul className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                        {projects.map((project) => (
                            <li key={project.id} className="xl:col-span-4">
                                <ProjectCard project={project} />
                            </li>
                        ))}
                    </ul>
                )}
            </PageLayout>
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
                <CardHeader className="pb-3">
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
                        <Badge variant="muted">{t('projects.disabled')}</Badge>
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
