import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    CHECK_STATUS_VARIANTS,
    checkLabel,
} from '@/components/reports/check-details';
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
import type { ReportHealth } from '@/types';

/** Statuses that need a look: the overview lists only these checks by default (#292). */
const PROBLEM_STATUSES = new Set(['fail', 'warn']);

/**
 * The checks of the latest report that did not pass, by group (infrastructure, configuration,
 * dependencies, then ids this app does not know), their status and how many findings they
 * raised. "All checks" shows every check; with nothing failing, one line says they all pass.
 */
export function ChecksCard({
    health,
    className,
}: {
    health: ReportHealth;
    className?: string;
}) {
    const { t } = useTranslation();
    const [isShowingAll, setIsShowingAll] = useState(false);

    const failingGroups = health.groups
        .map((group) => ({
            ...group,
            checks: group.checks.filter((check) =>
                PROBLEM_STATUSES.has(check.status),
            ),
        }))
        .filter((group) => group.checks.length > 0);
    // Skipped checks did not run: "All N checks pass" counts the ones that did.
    const ranCount = health.groups
        .flatMap((group) => group.checks)
        .filter((check) => check.status !== 'skipped').length;
    const groups = isShowingAll ? health.groups : failingGroups;

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.checks')}</CardTitle>
                <CardDescription>
                    {isShowingAll
                        ? t('report.checksDescription')
                        : t('report.overview.checksFailingDescription')}
                </CardDescription>
                <CardAction>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-expanded={isShowingAll}
                        onClick={() => setIsShowingAll(!isShowingAll)}
                    >
                        {isShowingAll
                            ? t('report.overview.checksFailingOnly')
                            : t('report.overview.allChecks')}
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent>
                {groups.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.overview.allChecksPassCount', {
                            count: ranCount,
                        })}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('report.check')}</TableHead>
                                <TableHead>
                                    {t('report.runtime.status')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('report.findings')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        {groups.map((group) => (
                            <TableBody key={group.category}>
                                <TableRow className="bg-muted/50 hover:bg-muted/50">
                                    <TableHead
                                        scope="colgroup"
                                        colSpan={2}
                                        className="font-medium text-foreground"
                                    >
                                        {t(`checks.category.${group.category}`)}
                                    </TableHead>
                                    <TableCell className="text-right">
                                        <Badge
                                            variant={
                                                CHECK_STATUS_VARIANTS[
                                                    group.status
                                                ]
                                            }
                                        >
                                            {t(`report.status.${group.status}`)}
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                                {group.checks.map((check) => (
                                    <TableRow key={check.checkId}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {checkLabel(
                                                    t,
                                                    check.checkId,
                                                    check.name,
                                                )}
                                            </div>
                                            <div className="font-mono text-xs text-muted-foreground">
                                                {check.checkId}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    CHECK_STATUS_VARIANTS[
                                                        check.status
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `report.status.${check.status}`,
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {check.issues}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        ))}
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
