import { Link, useForm } from '@inertiajs/react';
import { ShieldCheck, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { StackedBarChart } from '@/components/reports/severity-chart';
import {
    DEFAULT_TIME_RANGE,
    useRangeTexts,
} from '@/components/reports/time-range-picker';
import { StatCard } from '@/components/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import aiPricesRoutes from '@/routes/ai-prices';
import projectsRoutes from '@/routes/projects';
import type { AiUsageSummary, AiUsageTotals, RuntimeRow } from '@/types';

const AI_GUIDE_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/docs/guides/runtime-monitoring.md';

/** One column of a breakdown table: a header and a right-aligned numeric cell. */
type Column<Row> = {
    label: string;
    cell: (row: Row) => ReactNode;
    isProblem?: (row: Row) => boolean;
};

/**
 * The AI tab of a project (#129): what the Laravel AI SDK calls cost and how they behave over
 * the time range. Key numbers (estimated spend, calls and error rate, failovers, p95 latency),
 * the month to date against the budget (editable with `can-manage-alerts`), spend per chart
 * bucket, the token split, then the breakdown by model, provider and agent and the tool calls.
 * Costs are estimates from Settings → AI prices; a model without a price shows its tokens only.
 * The tab says that prompts and responses are never collected.
 */
export function AiUsageView({
    projectId,
    ai,
    canManage,
}: {
    projectId: number;
    ai: AiUsageSummary | null;
    canManage: boolean;
}) {
    const { t } = useTranslation();

    const rangeTexts = useRangeTexts(ai?.range ?? DEFAULT_TIME_RANGE);

    if (ai === null) {
        return (
            <div className="flex flex-col gap-4">
                <PrivacyNote />
                <PageEmptyState
                    icon={Sparkles}
                    title={t('ai.emptyTitle')}
                    description={t('ai.emptyDescription')}
                    docsHref={AI_GUIDE_URL}
                    docsLabel={t('runtime.guide')}
                />
            </div>
        );
    }

    const approx = ai.isSampled ? '~' : '';
    const count = (value: number): string =>
        `${approx}${value.toLocaleString()}`;
    const money = (value: number | null): string =>
        value === null
            ? '—'
            : value.toLocaleString(undefined, {
                  style: 'currency',
                  currency: 'USD',
                  maximumFractionDigits: value !== 0 && value < 1 ? 4 : 2,
              });
    const ms = (value: number | null): string =>
        value === null ? '—' : `${Math.round(value).toLocaleString()} ms`;
    const { totals } = ai;
    const { bucketText } = rangeTexts;
    const range = { bucket: rangeTexts.bucket, during: rangeTexts.during };

    const usageColumns: Column<AiUsageTotals>[] = [
        { label: t('ai.calls'), cell: (row) => count(row.count) },
        {
            label: t('ai.errors'),
            cell: (row) => count(row.errors),
            isProblem: (row) => row.errors > 0,
        },
        { label: t('ai.failovers'), cell: (row) => count(row.failovers) },
        { label: 'p95', cell: (row) => ms(row.p95) },
        {
            label: t('ai.inputTokens'),
            cell: (row) => count(row.uncachedInputTokens),
        },
        {
            label: t('ai.cachedTokens'),
            cell: (row) => count(row.cacheReadTokens + row.cacheWriteTokens),
        },
        { label: t('ai.outputTokens'), cell: (row) => count(row.outputTokens) },
        {
            label: t('ai.cost'),
            cell: (row) => (
                <span
                    title={row.hasUnpriced ? t('ai.unpricedHint') : undefined}
                >
                    {row.cost === null ? t('ai.noPrice') : money(row.cost)}
                    {row.cost !== null && row.hasUnpriced ? ' +' : ''}
                </span>
            ),
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <PrivacyNote />

            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
                <StatCard
                    className="xl:col-span-3"
                    label={t('ai.spend')}
                    value={money(totals.cost ?? 0)}
                    headline={rangeTexts.headline}
                    context={
                        totals.hasUnpriced
                            ? t('ai.unpricedHint')
                            : t('ai.estimate')
                    }
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('ai.calls')}
                    value={count(totals.count)}
                    headline={t('ai.errorRate', {
                        rate: (totals.errorRate ?? 0).toFixed(
                            (totals.errorRate ?? 0) < 1 ? 2 : 1,
                        ),
                    })}
                    context={ai.isSampled ? t('runtime.sampled') : undefined}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('ai.failovers')}
                    value={count(totals.failovers)}
                    headline={t('ai.failoversHint')}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('ai.p95')}
                    value={ms(totals.p95)}
                    headline={t('ai.allCalls')}
                    context={
                        totals.p50 === null
                            ? undefined
                            : `p50 ${ms(totals.p50)} · p99 ${ms(totals.p99)}`
                    }
                />
            </section>

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <Card className="xl:col-span-8">
                    <CardHeader className="pb-3">
                        <CardTitle>{t('ai.spendOverTime')}</CardTitle>
                        <CardDescription>
                            {t('ai.spendOverTimeDescription', range)}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="h-56">
                        <StackedBarChart
                            data={ai.timeline.map((point) => ({
                                label: point.at,
                                cost: point.cost,
                            }))}
                            formatLabel={bucketText}
                            formatValue={(value) => money(value)}
                            series={[
                                {
                                    key: 'cost',
                                    label: t('ai.spend'),
                                    color: 'var(--ai)',
                                },
                            ]}
                            label={t('ai.chartLabel', {
                                cost: money(totals.cost ?? 0),
                                ...range,
                            })}
                        />
                    </CardContent>
                </Card>
                <BudgetCard
                    className="xl:col-span-4"
                    projectId={projectId}
                    month={ai.month}
                    money={money}
                    canManage={canManage}
                />
            </section>

            <BreakdownCard
                title={t('ai.byModel')}
                description={t('ai.pricesNote', {
                    date: ai.pricesUpdatedOn,
                })}
                footer={
                    <Link
                        href={aiPricesRoutes.edit.url()}
                        className="text-sm underline underline-offset-4"
                    >
                        {t('ai.pricesLink')}
                    </Link>
                }
                rows={ai.models}
                name={(row) => (
                    <Name primary={row.model} secondary={row.provider} />
                )}
                columns={usageColumns}
            />

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <Card className="xl:col-span-4">
                    <CardHeader className="pb-3">
                        <CardTitle>{t('ai.tokens')}</CardTitle>
                        <CardDescription>
                            {t('ai.tokensDescription')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableBody>
                                {(
                                    [
                                        [
                                            'ai.inputTokens',
                                            totals.uncachedInputTokens,
                                        ],
                                        [
                                            'ai.cacheReadTokens',
                                            totals.cacheReadTokens,
                                        ],
                                        [
                                            'ai.cacheWriteTokens',
                                            totals.cacheWriteTokens,
                                        ],
                                        [
                                            'ai.outputTokens',
                                            totals.outputTokens,
                                        ],
                                        [
                                            'ai.reasoningTokens',
                                            totals.reasoningTokens,
                                        ],
                                    ] as const
                                ).map(([key, value]) => (
                                    <TableRow key={key}>
                                        <TableCell>{t(key)}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {count(value)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <BreakdownCard
                    className="xl:col-span-8"
                    title={t('ai.byProvider')}
                    rows={ai.providers}
                    name={(row) => <Name primary={row.provider} />}
                    columns={usageColumns.filter(
                        (column) => column.label !== 'p95',
                    )}
                />
            </section>

            <BreakdownCard
                title={t('ai.byAgent')}
                rows={ai.agents}
                name={(row) => (
                    <Name
                        primary={
                            row.agent ??
                            t(`ai.operation.${row.operation}`, {
                                defaultValue: row.operation,
                            })
                        }
                        secondary={
                            row.agent === null
                                ? undefined
                                : t('ai.operation.agent')
                        }
                        isMono={row.agent !== null}
                    />
                )}
                columns={usageColumns}
            />

            <BreakdownCard
                title={t('ai.tools')}
                description={t('ai.toolsDescription')}
                rows={ai.tools}
                name={(row: RuntimeRow) => (
                    <Name
                        primary={String(row.tool)}
                        secondary={String(row.agent)}
                        isMono
                    />
                )}
                columns={[
                    {
                        label: t('ai.calls'),
                        cell: (row) => count(row.count),
                    },
                    {
                        label: t('ai.failures'),
                        cell: (row) => count(row.errors),
                        isProblem: (row) => row.errors > 0,
                    },
                    {
                        label: t('runtime.average'),
                        cell: (row) => ms(row.averageMs),
                    },
                    { label: 'p95', cell: (row) => ms(row.p95) },
                    { label: t('ai.slowest'), cell: (row) => ms(row.maxMs) },
                ]}
            />
        </div>
    );
}

/** What Excubia receives about AI calls, and what it never does (#129). */
function PrivacyNote() {
    const { t } = useTranslation();

    return (
        <Alert variant="info">
            <ShieldCheck />
            <AlertDescription>{t('ai.privacy')}</AlertDescription>
        </Alert>
    );
}

/**
 * The estimated spend of the calendar month (UTC) against the project's monthly budget, which
 * alerts at 80 % and 100 %, with a field to set it for users who manage alerts.
 */
function BudgetCard({
    projectId,
    month,
    money,
    canManage,
    className,
}: {
    projectId: number;
    month: AiUsageSummary['month'];
    money: (value: number | null) => string;
    canManage: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    const form = useForm({
        ai_monthly_budget: month.budget === null ? '' : String(month.budget),
    });
    const share =
        month.budget === null || month.budget <= 0
            ? null
            : (month.cost / month.budget) * 100;

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('ai.month')}</CardTitle>
                <CardDescription>{t('ai.monthDescription')}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="flex items-baseline justify-between gap-2">
                    <span className="text-2xl font-semibold tabular-nums">
                        {money(month.cost)}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {month.budget === null
                            ? t('ai.noBudget')
                            : t('ai.ofBudget', { budget: money(month.budget) })}
                    </span>
                </div>
                {share !== null ? (
                    <>
                        <Progress
                            value={Math.min(share, 100)}
                            aria-label={t('ai.budgetUsed', {
                                percent: Math.round(share),
                            })}
                        />
                        <p
                            className={cn(
                                'text-sm',
                                share >= 100
                                    ? 'text-destructive'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {t('ai.budgetUsed', { percent: Math.round(share) })}
                        </p>
                    </>
                ) : null}
                {month.hasUnpriced ? (
                    <p className="text-sm text-muted-foreground">
                        {t('ai.unpricedHint')}
                    </p>
                ) : null}
                {canManage ? (
                    <form
                        className="flex flex-col gap-2"
                        noValidate
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(
                                projectsRoutes.aiBudget.update.url(projectId),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Field
                            data-invalid={
                                form.errors.ai_monthly_budget ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="ai_monthly_budget">
                                {t('ai.budget')}
                            </FieldLabel>
                            <div className="flex gap-2">
                                <Input
                                    id="ai_monthly_budget"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputMode="decimal"
                                    placeholder="100"
                                    value={form.data.ai_monthly_budget}
                                    onChange={(event) =>
                                        form.setData(
                                            'ai_monthly_budget',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        form.errors.ai_monthly_budget
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby="ai-monthly-budget-hint"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={form.processing}
                                >
                                    {t('common.save')}
                                </Button>
                            </div>
                            <FieldDescription id="ai-monthly-budget-hint">
                                {t('ai.budgetHint')}
                            </FieldDescription>
                            <FieldError>
                                {form.errors.ai_monthly_budget}
                            </FieldError>
                        </Field>
                    </form>
                ) : null}
            </CardContent>
        </Card>
    );
}

/** A breakdown table: a name column and right-aligned numeric columns, or "none in range". */
function BreakdownCard<Row>({
    title,
    description,
    footer,
    rows,
    name,
    columns,
    className,
}: {
    title: string;
    description?: string;
    footer?: ReactNode;
    rows: Row[];
    name: (row: Row) => ReactNode;
    columns: Column<Row>[];
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{title}</CardTitle>
                {description ? (
                    <CardDescription>{description}</CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('runtime.noData')}
                    </p>
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('runtime.name')}</TableHead>
                                    {columns.map((column) => (
                                        <TableHead
                                            key={column.label}
                                            className="text-right"
                                        >
                                            {column.label}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, index) => (
                                    <TableRow key={index}>
                                        <TableCell className="max-w-[24rem] min-w-0">
                                            {name(row)}
                                        </TableCell>
                                        {columns.map((column) => (
                                            <TableCell
                                                key={column.label}
                                                className={cn(
                                                    'text-right tabular-nums',
                                                    column.isProblem?.(row) &&
                                                        'text-destructive',
                                                )}
                                            >
                                                {column.cell(row)}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                {footer}
            </CardContent>
        </Card>
    );
}

/** A name with an optional muted second line; class names are monospace. */
function Name({
    primary,
    secondary,
    isMono = false,
}: {
    primary: string;
    secondary?: string;
    isMono?: boolean;
}) {
    return (
        <>
            <div
                className={cn(
                    'truncate',
                    isMono ? 'font-mono text-xs' : 'font-medium',
                )}
            >
                {primary}
            </div>
            {secondary ? (
                <div className="truncate text-xs text-muted-foreground">
                    {secondary}
                </div>
            ) : null}
        </>
    );
}
