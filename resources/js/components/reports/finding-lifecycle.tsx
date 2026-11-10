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
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import projectsRoutes from '@/routes/projects';
import type { FindingLifecycle, IssueRow } from '@/types';

/** A date and time in the user's language, from an ISO string. */
export function formatDateTime(iso: string, locale: string): string {
    return new Date(iso).toLocaleString(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** A `YYYY-MM-DD` day in the user's language. */
function formatDay(day: string, locale: string): string {
    return new Date(`${day}T00:00:00`).toLocaleDateString(locale, {
        dateStyle: 'medium',
    });
}

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
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
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
                    <dd>{formatDateTime(lifecycle.firstSeenAt, locale)}</dd>
                    <dt className="text-muted-foreground">
                        {t('findings.lastSeen')}
                    </dt>
                    <dd>{formatDateTime(lifecycle.lastSeenAt, locale)}</dd>
                </dl>
                <div className="flex flex-wrap items-center gap-1.5">
                    {lifecycle.resolvedAt !== null ? (
                        <Badge variant="success">
                            {t('findings.fixedOn', {
                                date: formatDateTime(
                                    lifecycle.resolvedAt,
                                    locale,
                                ),
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
                                      date: formatDay(dismissal.until, locale),
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
                            <Field
                                data-invalid={
                                    form.errors.until ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="finding_dismiss_until">
                                    {t('findings.until')}
                                </FieldLabel>
                                <Input
                                    id="finding_dismiss_until"
                                    type="date"
                                    min={today()}
                                    value={form.data.until}
                                    onChange={(event) =>
                                        form.setData(
                                            'until',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        form.errors.until ? true : undefined
                                    }
                                    aria-describedby="finding_dismiss_until_hint"
                                />
                                <FieldDescription id="finding_dismiss_until_hint">
                                    {t('findings.untilHint')}
                                </FieldDescription>
                                <FieldError>{form.errors.until}</FieldError>
                            </Field>
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
                                    {formatDateTime(event.occurredAt, locale)}
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
                                                  date: formatDay(
                                                      event.until,
                                                      locale,
                                                  ),
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
