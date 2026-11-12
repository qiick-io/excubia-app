import { useTranslation } from 'react-i18next';
import {
    CHECK_STATUS_VARIANTS,
    checkLabel,
} from '@/components/reports/check-details';
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
import type { ReportHealth } from '@/types';

/**
 * Every check of the latest report by group (infrastructure, configuration, dependencies, then
 * ids this app does not know), its status and how many findings it raised.
 */
export function ChecksCard({
    health,
    className,
}: {
    health: ReportHealth;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.checks')}</CardTitle>
                <CardDescription>
                    {t('report.checksDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('report.check')}</TableHead>
                            <TableHead>{t('report.runtime.status')}</TableHead>
                            <TableHead className="text-right">
                                {t('report.findings')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    {health.groups.map((group) => (
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
                                            CHECK_STATUS_VARIANTS[group.status]
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
                                            {t(`report.status.${check.status}`)}
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
            </CardContent>
        </Card>
    );
}
