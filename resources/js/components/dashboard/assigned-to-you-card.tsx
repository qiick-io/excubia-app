import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import projectsRoutes from '@/routes/projects';
import type { AssignedRow } from '@/types/dashboard';

/**
 * "Assigned to you" (#254): per project, the open exception groups and findings the user is on,
 * each count linking to its tab with the "Assigned to me" filter on (`?assigned=me`). Shown
 * only when something is assigned.
 */
export function AssignedToYouCard({ rows }: { rows: AssignedRow[] }) {
    const { t } = useTranslation();
    const mine = { query: { assigned: 'me' } };

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.assigned.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.assigned.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="flex flex-col divide-y divide-border text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.slug}
                            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2"
                        >
                            <span className="min-w-0 truncate font-medium">
                                {row.name}
                            </span>
                            <span className="flex gap-4">
                                {row.exceptions > 0 ? (
                                    <Link
                                        className="text-primary underline-offset-4 hover:underline"
                                        href={projectsRoutes.view.url(
                                            {
                                                project: row.slug,
                                                view: 'exceptions',
                                            },
                                            mine,
                                        )}
                                    >
                                        {t('dashboard.assigned.exceptions', {
                                            count: row.exceptions,
                                        })}
                                    </Link>
                                ) : null}
                                {row.findings > 0 ? (
                                    <Link
                                        className="text-primary underline-offset-4 hover:underline"
                                        href={projectsRoutes.view.url(
                                            {
                                                project: row.slug,
                                                view: 'findings',
                                            },
                                            mine,
                                        )}
                                    >
                                        {t('dashboard.assigned.findings', {
                                            count: row.findings,
                                        })}
                                    </Link>
                                ) : null}
                            </span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
