import { usePoll } from '@inertiajs/react';
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
import type { LiveHealthCheck } from '@/types';
import { relativeTime } from '@/lib/report-format';

/** How often the overview reloads the live health (#115): samples arrive once a minute. */
const LIVE_HEALTH_POLL_MS = 60_000;

/**
 * Live health (#115): the minute-level samples of the core `health` runtime type, per check
 * (failing first) with each server's latest reading and when it last sent one. Reloads only
 * its own prop every minute while the overview is open.
 */
export function LiveHealthCard({ checks }: { checks: LiveHealthCheck[] }) {
    const { t, i18n } = useTranslation();

    usePoll(LIVE_HEALTH_POLL_MS, { only: ['liveHealth'] });

    return (
        <Card data-test="live-health">
            <CardHeader className="pb-3">
                <CardTitle>{t('report.liveHealth.title')}</CardTitle>
                <CardDescription>
                    {t('report.liveHealth.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('report.check')}</TableHead>
                            <TableHead>{t('report.runtime.status')}</TableHead>
                            <TableHead>
                                {t('report.liveHealth.servers')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('report.liveHealth.lastSample')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {checks.map((check) => (
                            <TableRow key={check.checkId}>
                                <TableCell>
                                    <div className="font-medium">
                                        {checkLabel(t, check.checkId)}
                                    </div>
                                    <div className="font-mono text-xs text-muted-foreground">
                                        {check.checkId}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant={
                                            CHECK_STATUS_VARIANTS[check.status]
                                        }
                                    >
                                        {t(`report.status.${check.status}`)}
                                    </Badge>
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    <div className="flex flex-wrap gap-1">
                                        {check.servers.map((server) => (
                                            <Badge
                                                key={server.server}
                                                variant={
                                                    server.isStale
                                                        ? 'outline'
                                                        : CHECK_STATUS_VARIANTS[
                                                              server.status
                                                          ]
                                                }
                                                title={relativeTime(
                                                    server.lastSampleAt,
                                                    i18n.language,
                                                )}
                                            >
                                                {server.isStale
                                                    ? t(
                                                          'report.liveHealth.serverStale',
                                                          {
                                                              server: server.server,
                                                          },
                                                      )
                                                    : t(
                                                          'report.liveHealth.serverStatus',
                                                          {
                                                              server: server.server,
                                                              status: t(
                                                                  `report.status.${server.status}`,
                                                              ),
                                                          },
                                                      )}
                                            </Badge>
                                        ))}
                                    </div>
                                </TableCell>
                                <TableCell className="text-right text-muted-foreground">
                                    {relativeTime(
                                        check.lastSampleAt,
                                        i18n.language,
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}
