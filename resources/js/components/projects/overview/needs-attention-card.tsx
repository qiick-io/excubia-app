import { Link } from '@inertiajs/react';
import { Bug, CircleCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { checkLabel } from '@/components/reports/check-details';
import type { FindingFix } from '@/components/reports/fix-actions';
import { FixActions } from '@/components/reports/fix-actions';
import { SeverityBadge } from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
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
import type { ExceptionGroupRow, IssueRow } from '@/types';

export type AttentionItem =
    | {
          kind: 'finding';
          key: string;
          finding: IssueRow;
          fix: FindingFix | null;
      }
    | { kind: 'exception'; key: string; group: ExceptionGroupRow };

/**
 * What to fix first: the worst findings, then the open exceptions seen most recently, each
 * with its ready command or prompt. The tabs list everything.
 */
export function NeedsAttentionCard({
    slug,
    items,
    total,
}: {
    slug: string;
    items: AttentionItem[];
    total: number;
}) {
    const { t } = useTranslation();
    const findingsUrl = projectsRoutes.view.url({
        project: slug,
        view: 'findings',
    });
    const exceptionsUrl = projectsRoutes.view.url({
        project: slug,
        view: 'exceptions',
    });

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.overview.attention.title')}</CardTitle>
                <CardDescription>
                    {t('report.overview.attention.description', {
                        shown: items.length,
                        total,
                    })}
                </CardDescription>
                <CardAction>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={findingsUrl}>
                            {t('report.overview.attention.allFindings')}
                        </Link>
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <PageEmptyState
                        icon={CircleCheck}
                        title={t('report.overview.attention.emptyTitle')}
                        description={t('report.noIssuesDescription')}
                        compact
                        className="border-0"
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[1%]">
                                    {t('report.severityLabel')}
                                </TableHead>
                                <TableHead>
                                    {t('dashboard.topIssues.issue')}
                                </TableHead>
                                <TableHead className="w-[1%] text-right">
                                    {t('fix.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) =>
                                item.kind === 'finding' ? (
                                    <TableRow key={item.key}>
                                        <TableCell>
                                            <SeverityBadge
                                                severity={item.finding.severity}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-[40rem] whitespace-normal">
                                            <Link
                                                href={`${findingsUrl}?finding=${encodeURIComponent(item.key)}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {item.finding.subject}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {checkLabel(
                                                    t,
                                                    item.finding.checkId,
                                                    item.finding.checkName,
                                                )}
                                                {item.finding.advisoryId ? (
                                                    <span className="font-mono">
                                                        {' · '}
                                                        {
                                                            item.finding
                                                                .advisoryId
                                                        }
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {item.finding.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {item.fix ? (
                                                <FixActions fix={item.fix} />
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    <TableRow key={item.key}>
                                        <TableCell>
                                            <Badge variant="outline">
                                                <Bug />
                                                {t(
                                                    'report.overview.attention.exception',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-[40rem] whitespace-normal">
                                            <Link
                                                href={exceptionsUrl}
                                                className="font-mono text-xs font-medium break-all underline-offset-4 hover:underline"
                                            >
                                                {item.group.class}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {item.group.context.name} ·{' '}
                                                {t('report.overview.events', {
                                                    count: item.group.count,
                                                })}
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {item.group.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <FixActions fix={item.group.fix} />
                                        </TableCell>
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
