import { useTranslation } from 'react-i18next';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { HealthReportBody } from '@/types';

/** Plan tab of the project page: the upgrade plan of the latest report, step by step. */
export function PlanView({ report }: { report: HealthReportBody }) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.upgradePlan')}</CardTitle>
                <CardDescription>{t('report.planDescription')}</CardDescription>
            </CardHeader>
            <CardContent>
                {report.upgradePlan.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.noUpgradeSteps')}
                    </p>
                ) : (
                    <ol className="list-decimal space-y-2 pl-6 text-sm">
                        {report.upgradePlan.map((step) => (
                            <li key={step.stepNumber}>{step.message}</li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
