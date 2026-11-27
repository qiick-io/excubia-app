import { router, useForm } from '@inertiajs/react';
import { BellOff, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CopySnippet } from '@/components/projects/copy-snippet';
import {
    AssigneeSelect,
    type AssigneeOption,
} from '@/components/reports/assignee-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { DatePicker } from '@/components/admin/date-picker';
import { useClipboard } from '@/hooks/use-clipboard';
import projectsRoutes from '@/routes/projects';
import type { FindingLifecycle, IssueRow } from '@/types';
import { useDateFormat } from '@/lib/date-format';

/** Today as `YYYY-MM-DD` in the browser's timezone, the earliest last day of a dismissal. */
function today(): string {
    const now = new Date();
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/**
 * The status badges of a finding across reports (#18): dismissed, how often it came back.
 * Open and resolved are already told apart by the Findings tab filter.
 */
export function LifecycleBadges({
    lifecycle,
}: {
    lifecycle: FindingLifecycle;
}) {
    const { t } = useTranslation();

    return (
        <>
            {lifecycle.dismissal !== null ? (
                <Badge variant="muted">{t('findings.status.dismissed')}</Badge>
            ) : null}
            {lifecycle.reopenedCount > 0 ? (
                <Badge variant="warning">
                    {t('findings.reopened', { count: lifecycle.reopenedCount })}
                </Badge>
            ) : null}
        </>
    );
}

/**
 * The lifecycle part of the finding drawer (#18): who is on it (#254), first and last seen, when a report fixed it,
 * the active dismissal with its excubia.json ignore rule, the history, and (with
 * `can-dismiss-findings`) an inline form to dismiss it with a reason and an optional last day,
 * or a button to remove the dismissal.
 */
export function FindingLifecycleSection({
    finding,
    lifecycle,
    projectId,
    canDismiss,
    assignees = [],
}: {
    finding: IssueRow;
    lifecycle: FindingLifecycle;
    projectId: number;
    canDismiss: boolean;
    /** Who the finding may be assigned to (#254). */
    assignees?: AssigneeOption[];
}) {
    const { t } = useTranslation();
    const dates = useDateFormat();
    const [isDismissing, setIsDismissing] = useState(false);
    const [copiedText, copy] = useClipboard();
    const form = useForm({ is_dismissed: true, reason: '', until: '' });
    const url = projectsRoutes.findings.update.url({
        project: projectId,
        finding: lifecycle.id,
    });
    const dismissal = lifecycle.dismissal;

    // Same fields as an `ignore` rule of excubia.json in qiick/excubia-core.
    const ignoreRule =
        dismissal === null
            ? null
            : JSON.stringify(
                  {
                      checkId: finding.checkId,
                      subject: finding.subject,
                      advisoryId: finding.advisoryId ?? null,
                      reason: dismissal.reason ?? '',
                      until: dismissal.until,
                  },
                  null,
                  4,
              );

    return (
        <>
            <AssigneeSelect
                id={`finding-assignee-${lifecycle.id}`}
                url={projectsRoutes.findings.assignee.url({
                    project: projectId,
                    finding: lifecycle.id,
                })}
                assignee={lifecycle.assignee}
                options={assignees}
                canAssign={canDismiss}
            />
            <section className="flex flex-col gap-2">
                <h3 className="text-sm font-medium">
                    {t('findings.lifecycle')}
                </h3>
                <dl className="grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-4 gap-y-1.5 text-sm">
                    <dt className="text-muted-foreground">
                        {t('findings.firstSeen')}
                    </dt>
                    <dd>{dates.dateTime(lifecycle.firstSeenAt)}</dd>
                    <dt className="text-muted-foreground">
                        {t('findings.lastSeen')}
                    </dt>
                    <dd>{dates.dateTime(lifecycle.lastSeenAt)}</dd>
                </dl>
                <div className="flex flex-wrap items-center gap-1.5">
                    {lifecycle.resolvedAt !== null ? (
                        <Badge variant="success">
                            {t('findings.fixedOn', {
                                date: dates.dateTime(lifecycle.resolvedAt),
                            })}
                        </Badge>
                    ) : null}
                    <LifecycleBadges lifecycle={lifecycle} />
                </div>
                {dismissal !== null ? (
                    <p className="text-sm">
                        <span className="text-muted-foreground">
                            {dismissal.until !== null
                                ? t('findings.dismissedUntil', {
                                      date: dates.day(dismissal.until),
                                  })
                                : t('findings.dismissedForGood')}
                            {dismissal.by !== null
                                ? ` · ${t('findings.byUser', { name: dismissal.by })}`
                                : ''}
                            :
                        </span>{' '}
                        {dismissal.reason}
                    </p>
                ) : null}
                {canDismiss && !isDismissing ? (
                    <div className="flex flex-wrap gap-2">
                        {dismissal === null ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsDismissing(true)}
                            >
                                <BellOff className="size-4" />
                                {t('findings.dismiss')}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.patch(
                                        url,
                                        { is_dismissed: false },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                        },
                                    )
                                }
                            >
                                <Undo2 className="size-4" />
                                {t('findings.undismiss')}
                            </Button>
                        )}
                    </div>
                ) : null}
                {isDismissing ? (
                    <form
                        className="flex flex-col gap-3 rounded-md border p-3"
                        aria-label={t('findings.dismissTitle')}
                        noValidate
                        onSubmit={(event) => {
                            event.preventDefault();

                            // The date picker reports an invalid typed date here (app-ui date-picker).
                            if (!event.currentTarget.checkValidity()) {
                                return;
                            }

                            form.patch(url, {
                                preserveScroll: true,
                                preserveState: true,
                                onSuccess: () => {
                                    form.reset();
                                    setIsDismissing(false);
                                },
                            });
                        }}
                    >
                        <p className="text-sm text-muted-foreground">
                            {t('findings.dismissDescription')}
                        </p>
                        <FieldGroup className="gap-3">
                            <Field
                                data-invalid={
                                    form.errors.reason ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="finding_dismiss_reason">
                                    {t('findings.reason')}
                                </FieldLabel>
                                <Textarea
                                    id="finding_dismiss_reason"
                                    value={form.data.reason}
                                    maxLength={500}
                                    required
                                    placeholder={t(
                                        'findings.reasonPlaceholder',
                                    )}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        form.errors.reason ? true : undefined
                                    }
                                />
                                <FieldError>{form.errors.reason}</FieldError>
                            </Field>
                            <div className="grid gap-2">
                                <DatePicker
                                    label={t('findings.until')}
                                    value={form.data.until || null}
                                    onChange={(value) =>
                                        form.setData('until', value ?? '')
                                    }
                                    min={today()}
                                    error={form.errors.until}
                                />
                                <FieldDescription>
                                    {t('findings.untilHint')}
                                </FieldDescription>
                            </div>
                        </FieldGroup>
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    form.reset();
                                    form.clearErrors();
                                    setIsDismissing(false);
                                }}
                            >
                                {t('findings.cancel')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {t('findings.confirmDismiss')}
                            </Button>
                        </div>
                    </form>
                ) : null}
                {ignoreRule !== null ? (
                    <div className="flex flex-col gap-1">
                        <CopySnippet
                            label={t('findings.ignoreRule')}
                            text={ignoreRule}
                            copiedText={copiedText}
                            copy={copy}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('findings.ignoreRuleHint')}
                        </p>
                    </div>
                ) : null}
            </section>
            <section className="flex flex-col gap-2">
                <h3 className="text-sm font-medium">{t('findings.history')}</h3>
                <ol className="divide-y rounded-md border">
                    {[...lifecycle.history].reverse().map((event, index) => (
                        <li
                            key={`${event.event}-${event.occurredAt}-${index}`}
                            className="flex flex-col gap-0.5 px-3 py-1.5 text-sm"
                        >
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span className="font-medium">
                                    {t(`findings.event.${event.event}`)}
                                </span>
                                <time
                                    dateTime={event.occurredAt}
                                    className="text-xs text-muted-foreground"
                                >
                                    {dates.dateTime(event.occurredAt)}
                                </time>
                            </div>
                            {event.release !== null ||
                            event.assignee !== null ||
                            event.by !== null ||
                            event.until !== null ||
                            event.reason !== null ? (
                                <span className="text-xs break-words text-muted-foreground">
                                    {[
                                        event.release !== null
                                            ? t('findings.inRelease', {
                                                  release: event.release,
                                              })
                                            : null,
                                        event.assignee !== null
                                            ? t('findings.toUser', {
                                                  name: event.assignee,
                                              })
                                            : null,
                                        event.by !== null
                                            ? t('findings.byUser', {
                                                  name: event.by,
                                              })
                                            : null,
                                        event.until !== null
                                            ? t('findings.untilDate', {
                                                  date: dates.day(event.until),
                                              })
                                            : null,
                                        event.reason,
                                    ]
                                        .filter((part) => part !== null)
                                        .join(' · ')}
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ol>
            </section>
        </>
    );
}
