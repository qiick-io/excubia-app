import { useTranslation } from 'react-i18next';
import {
    RUNTIMES,
    RuntimeSupportBadge,
} from '@/components/reports/runtime-support';
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
import type { HealthReportSummary } from '@/types';

/** Each runtime of the latest report, its version and its security support. */
export function RuntimesCard({
    latest,
    className,
}: {
    latest: HealthReportSummary;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.runtime.title')}</CardTitle>
                <CardDescription>
                    {t('report.runtime.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {latest.runtime === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.runtime.unavailable')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('report.runtime.name')}
                                </TableHead>
                                <TableHead>
                                    {t('report.runtime.version')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('report.runtime.securityUntil')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('report.runtime.status')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {RUNTIMES.map((name) => {
                                const runtime = latest.runtime?.[name] ?? null;

                                return (
                                    <TableRow key={name}>
                                        <TableCell className="font-medium">
                                            {t(`report.runtime.${name}`)}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {runtime?.version ?? '—'}
                                            {runtime?.cycle ? (
                                                <span className="ml-1 text-muted-foreground">
                                                    ({runtime.cycle})
                                                </span>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {runtime?.security_support_ends_on ??
                                                '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {runtime === null ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {t(
                                                        'report.runtime.notUsed',
                                                    )}
                                                </span>
                                            ) : (
                                                <RuntimeSupportBadge
                                                    runtime={runtime}
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
