import { Scale } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { CHECKS_DOCS_URL } from '@/components/reports/check-details';
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
import type { FlaggedLicense, ReportHealth } from '@/types';

export const LICENSE_KIND_VARIANTS = {
    denied: 'destructive',
    copyleft: 'warning',
    unknown: 'secondary',
} as const satisfies Record<FlaggedLicense['kind'], string>;

/**
 * The Licenses tab (#108): the denied, strong copyleft and unknown packages first, then the
 * inventory of the latest report, packages per license id and ecosystem (core #134).
 */
export function LicensesView({
    licenses,
}: {
    licenses: ReportHealth['licenses'];
}) {
    const { t } = useTranslation();

    if (licenses === null) {
        return (
            <PageEmptyState
                icon={Scale}
                title={t('licenses.emptyTitle')}
                description={t('licenses.unavailable')}
                docsHref={CHECKS_DOCS_URL}
                docsLabel={t('checks.docs')}
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle>{t('licenses.flaggedTitle')}</CardTitle>
                    <CardDescription>
                        {t('licenses.flaggedDescription')}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {licenses.flagged.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('licenses.noneFlagged')}
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('licenses.kind')}</TableHead>
                                    <TableHead>
                                        {t('details.package')}
                                    </TableHead>
                                    <TableHead>
                                        {t('details.lockedVersion')}
                                    </TableHead>
                                    <TableHead>
                                        {t('details.licenses')}
                                    </TableHead>
                                    <TableHead>
                                        {t('details.ecosystem')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {licenses.flagged.map((row) => (
                                    <TableRow
                                        key={`${row.ecosystem}-${row.package}`}
                                    >
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
                                        <TableCell className="font-medium">
                                            {row.package}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {row.lockedVersion || '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {row.licenses.length === 0
                                                ? t('licenses.none')
                                                : row.licenses.join(', ')}
                                        </TableCell>
                                        <TableCell>
                                            {t(
                                                `health.ecosystem.${row.ecosystem}`,
                                                { defaultValue: row.ecosystem },
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle>{t('licenses.inventoryTitle')}</CardTitle>
                    <CardDescription>
                        {t('licenses.packages', {
                            count: licenses.packageCount,
                        })}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('licenses.license')}</TableHead>
                                <TableHead className="text-right">
                                    {t('health.ecosystem.composer')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('health.ecosystem.npm')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('licenses.total')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {licenses.byLicense.map((row) => (
                                <TableRow key={row.license}>
                                    <TableCell className="font-mono text-xs">
                                        {row.license}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.composer}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.npm}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {row.total}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}
