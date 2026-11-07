import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    ExternalLink,
    PackageSearch,
    Rocket,
    ShieldAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import {
    RuntimeSupportBadge,
    supportStatus,
} from '@/components/reports/runtime-support';
import { SeverityBadge } from '@/components/reports/severity-badge';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import fleetRoutes from '@/routes/fleet';
import projectsRoutes from '@/routes/projects';
import type { BreadcrumbItem, RuntimeName, Severity } from '@/types';

type FleetView = 'readiness' | 'advisories' | 'eol';

type ProjectRef = { slug: string; name: string };

/** `FleetService::upgradeReadiness()` (#19). */
type Readiness = {
    targets: { targetMajor: number; ready: number; total: number }[];
    unknown: number;
    projects: {
        project: ProjectRef;
        laravelVersion: string | null;
        targetMajor: number | null;
        status: 'ready' | 'blocked' | 'unknown';
        blockers: {
            package: string;
            lockedVersion: string;
            isPreReleaseOnly: boolean;
        }[];
        uncheckedPackages: number;
        hasReport: boolean;
        planSteps: number;
    }[];
};

type ExposureKind = 'advisory' | 'abandoned' | 'stale';

/** `FleetService::advisoryExposure()` (#20): one package or advisory and its projects. */
type Exposure = {
    key: string;
    kind: ExposureKind;
    ecosystem: 'composer' | 'npm';
    package: string;
    advisoryId: string | null;
    evidenceUrl: string | null;
    replacement: string | null;
    severity: Severity;
    projects: (ProjectRef & {
        lockedVersion: string;
        fixedVersions: string[];
        severity: Severity;
    })[];
};

type CalendarRuntime = {
    name: RuntimeName;
    version: string | null;
    cycle: string | null;
    activeSupportEndsOn: string | null;
    securitySupportEndsOn: string | null;
};

/** `FleetService::endOfLifeCalendar()` (#23). */
type CalendarRow = {
    project: ProjectRef;
    nearestEndsOn: string | null;
    runtimes: CalendarRuntime[];
};

const VIEWS: FleetView[] = ['readiness', 'advisories', 'eol'];

const VIEW_ICONS: Record<FleetView, LucideIcon> = {
    readiness: Rocket,
    advisories: ShieldAlert,
    eol: CalendarClock,
};

const READINESS_VARIANTS = {
    ready: 'success',
    blocked: 'warning',
    unknown: 'muted',
} as const;

const EXPOSURE_KINDS: ExposureKind[] = ['advisory', 'abandoned', 'stale'];

/**
 * Fleet (`/fleet/{view}`): upgrade readiness (#19), advisory exposure (#20) and the end-of-life
 * calendar (#23) across the projects the user may see, from the latest report of each.
 */
export default function FleetIndex({
    view,
    readiness,
    advisories,
    search,
    calendar,
}: {
    view: FleetView;
    readiness: Readiness | null;
    advisories: Exposure[] | null;
    search: string;
    calendar: CalendarRow[] | null;
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('fleet.title'), href: fleetRoutes.show.url() },
            {
                title: t(`fleet.views.${view}`),
                href: fleetRoutes.view.url(view),
            },
        ],
        [t, view],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('fleet.title')}: ${t(`fleet.views.${view}`)}`} />
            <FleetTabs view={view} />
            {view === 'advisories' && advisories !== null ? (
                <AdvisoriesView rows={advisories} initialSearch={search} />
            ) : (
                <PageLayout scrollContent>
                    {view === 'readiness' && readiness !== null ? (
                        <ReadinessView readiness={readiness} />
                    ) : view === 'eol' && calendar !== null ? (
                        <CalendarView rows={calendar} />
                    ) : null}
                </PageLayout>
            )}
        </AppLayout>
    );
}

/**
 * The Fleet tabs (shadcn `Tabs`, `line` variant) as links to their own URLs, as on the project page.
 */
function FleetTabs({ view }: { view: FleetView }) {
    const { t } = useTranslation();

    return (
        <Tabs
            value={view}
            activationMode="manual"
            className="overflow-x-auto border-b px-4"
        >
            <TabsList variant="line" aria-label={t('fleet.views.label')}>
                {VIEWS.map((item) => {
                    const Icon = VIEW_ICONS[item];

                    return (
                        <TabsTrigger
                            key={item}
                            value={item}
                            // Links, no TabsContent: Radix's aria-controls would point at a missing id.
                            aria-controls={undefined}
                            asChild
                        >
                            <Link
                                href={
                                    item === 'readiness'
                                        ? fleetRoutes.show.url()
                                        : fleetRoutes.view.url(item)
                                }
                            >
                                <Icon />
                                {t(`fleet.views.${item}`)}
                            </Link>
                        </TabsTrigger>
                    );
                })}
            </TabsList>
        </Tabs>
    );
}

function ProjectLink({ project }: { project: ProjectRef }) {
    return (
        <Link
            href={projectsRoutes.show.url(project.slug)}
            className="font-medium underline-offset-4 hover:underline"
        >
            {project.name}
        </Link>
    );
}

/**
 * Upgrade readiness (#19): per next Laravel major, how many projects can move today, then each
 * project with the packages that block it and a link to its upgrade plan.
 */
function ReadinessView({ readiness }: { readiness: Readiness }) {
    const { t } = useTranslation();

    if (readiness.projects.length === 0) {
        return (
            <PageEmptyState
                icon={Rocket}
                title={t('fleet.empty.title')}
                description={t('fleet.empty.description')}
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t('fleet.readiness.description')}
            </p>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {readiness.targets.map((target) => (
                    <StatCard
                        key={target.targetMajor}
                        label={t('fleet.readiness.target', {
                            major: target.targetMajor,
                        })}
                        value={`${target.ready} / ${target.total}`}
                        headline={t('fleet.readiness.canMove', {
                            ready: target.ready,
                            total: target.total,
                            major: target.targetMajor,
                        })}
                    />
                ))}
                {readiness.unknown > 0 ? (
                    <StatCard
                        label={t('fleet.readiness.status.unknown')}
                        value={readiness.unknown.toLocaleString()}
                        context={t('fleet.readiness.unknownDescription')}
                    />
                ) : null}
            </div>
            <TablePanel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('fleet.project')}</TableHead>
                            <TableHead>{t('report.runtime.laravel')}</TableHead>
                            <TableHead>{t('fleet.status')}</TableHead>
                            <TableHead>
                                {t('fleet.readiness.blockers')}
                            </TableHead>
                            <TableHead>{t('report.upgradePlan')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {readiness.projects.map((row) => (
                            <TableRow key={row.project.slug}>
                                <TableCell className="align-top">
                                    <ProjectLink project={row.project} />
                                </TableCell>
                                <TableCell className="align-top font-mono text-xs">
                                    {row.laravelVersion ?? '—'}
                                </TableCell>
                                <TableCell className="align-top">
                                    <Badge
                                        variant={READINESS_VARIANTS[row.status]}
                                    >
                                        {row.status === 'unknown' ||
                                        row.targetMajor === null
                                            ? t(
                                                  'fleet.readiness.status.unknown',
                                              )
                                            : t(
                                                  `fleet.readiness.status.${row.status}`,
                                                  { major: row.targetMajor },
                                              )}
                                    </Badge>
                                </TableCell>
                                <TableCell className="align-top whitespace-normal">
                                    {row.blockers.length === 0 ? (
                                        <span className="text-sm text-muted-foreground">
                                            {row.hasReport
                                                ? '—'
                                                : t('dashboard.noReports')}
                                        </span>
                                    ) : (
                                        <ul className="space-y-1 text-sm">
                                            {row.blockers.map((blocker) => (
                                                <li
                                                    key={blocker.package}
                                                    className="flex flex-wrap items-center gap-1"
                                                >
                                                    <span className="font-mono text-xs">
                                                        {blocker.package}
                                                    </span>
                                                    <span className="font-mono text-xs text-muted-foreground">
                                                        {blocker.lockedVersion}
                                                    </span>
                                                    {blocker.isPreReleaseOnly ? (
                                                        <Badge variant="muted">
                                                            {t(
                                                                'fleet.readiness.preReleaseOnly',
                                                            )}
                                                        </Badge>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {row.uncheckedPackages > 0 ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {t(
                                                'fleet.readiness.uncheckedPackages',
                                                {
                                                    count: row.uncheckedPackages,
                                                },
                                            )}
                                        </p>
                                    ) : null}
                                </TableCell>
                                <TableCell className="align-top">
                                    {row.hasReport ? (
                                        <Link
                                            href={projectsRoutes.view.url({
                                                project: row.project.slug,
                                                view: 'plan',
                                            })}
                                            className="text-sm text-primary underline underline-offset-4"
                                        >
                                            {t('fleet.readiness.planLink', {
                                                count: row.planSteps,
                                            })}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </TablePanel>
        </div>
    );
}

/**
 * Advisory exposure (#20): package or advisory → projects with locked and fixed versions,
 * searchable by package or advisory id and filtered by kind, in the browser.
 */
function AdvisoriesView({
    rows,
    initialSearch,
}: {
    rows: Exposure[];
    initialSearch: string;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(initialSearch);
    const [kind, setKind] = useState<ExposureKind | ''>('');
    const needle = search.trim().toLowerCase();
    const filtered = rows.filter(
        (row) =>
            (kind === '' || row.kind === kind) &&
            (needle === '' ||
                row.package.toLowerCase().includes(needle) ||
                (row.advisoryId ?? '').toLowerCase().includes(needle)),
    );

    return (
        <PageLayout
            description={t('fleet.advisories.description')}
            filters={
                <>
                    <FilterSearch
                        value={search}
                        onChange={setSearch}
                        placeholder={t('fleet.advisories.search')}
                        dataTest="fleet-advisory-search"
                    />
                    <NativeSelect
                        value={kind}
                        onChange={(changeEvent) =>
                            setKind(
                                changeEvent.target.value as ExposureKind | '',
                            )
                        }
                        className="max-w-[12rem] min-w-[8rem] shrink-0"
                        aria-label={t('fleet.advisories.kindLabel')}
                    >
                        <NativeSelectOption value="">
                            {t('fleet.advisories.allKinds')}
                        </NativeSelectOption>
                        {EXPOSURE_KINDS.map((item) => (
                            <NativeSelectOption key={item} value={item}>
                                {t(`fleet.advisories.kind.${item}`)}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                </>
            }
        >
            {filtered.length === 0 ? (
                <PageEmptyState
                    icon={PackageSearch}
                    title={
                        rows.length === 0
                            ? t('fleet.advisories.emptyTitle')
                            : t('fleet.advisories.noMatchTitle')
                    }
                    description={
                        rows.length === 0
                            ? t('fleet.advisories.emptyDescription')
                            : t('fleet.advisories.noMatchDescription')
                    }
                />
            ) : (
                <TablePanel>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('fleet.advisories.package')}
                                </TableHead>
                                <TableHead>
                                    {t('fleet.advisories.advisory')}
                                </TableHead>
                                <TableHead>
                                    {t('report.severityLabel')}
                                </TableHead>
                                <TableHead>
                                    {t('fleet.advisories.projects')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filtered.map((row) => (
                                <TableRow key={row.key}>
                                    <TableCell className="align-top">
                                        <div className="font-mono text-xs">
                                            {row.package}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {t(
                                                `fleet.advisories.ecosystem.${row.ecosystem}`,
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="align-top whitespace-normal">
                                        <div className="flex flex-wrap items-center gap-1">
                                            <Badge variant="outline">
                                                {t(
                                                    `fleet.advisories.kind.${row.kind}`,
                                                )}
                                            </Badge>
                                            {row.advisoryId !== null ? (
                                                row.evidenceUrl !== null ? (
                                                    <a
                                                        href={row.evidenceUrl}
                                                        target="_blank"
                                                        rel="noreferrer noopener"
                                                        className="inline-flex items-center gap-0.5 font-mono text-xs text-primary underline underline-offset-4"
                                                    >
                                                        {row.advisoryId}
                                                        <ExternalLink
                                                            className="size-3"
                                                            aria-hidden
                                                        />
                                                    </a>
                                                ) : (
                                                    <span className="font-mono text-xs">
                                                        {row.advisoryId}
                                                    </span>
                                                )
                                            ) : null}
                                        </div>
                                        {row.replacement !== null ? (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {t(
                                                    'fleet.advisories.replacement',
                                                    {
                                                        package:
                                                            row.replacement,
                                                    },
                                                )}
                                            </p>
                                        ) : null}
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <SeverityBadge
                                            severity={row.severity}
                                        />
                                    </TableCell>
                                    <TableCell className="align-top whitespace-normal">
                                        <ul className="space-y-1 text-sm">
                                            {row.projects.map((project) => (
                                                <li
                                                    key={project.slug}
                                                    className="flex flex-wrap items-center gap-x-2"
                                                >
                                                    <ProjectLink
                                                        project={project}
                                                    />
                                                    <span className="font-mono text-xs">
                                                        {project.lockedVersion ||
                                                            '—'}
                                                        {project.fixedVersions
                                                            .length > 0
                                                            ? ` → ${project.fixedVersions.join(', ')}`
                                                            : ''}
                                                    </span>
                                                    {project.severity !==
                                                    row.severity ? (
                                                        <SeverityBadge
                                                            severity={
                                                                project.severity
                                                            }
                                                        />
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TablePanel>
            )}
        </PageLayout>
    );
}

const DAY_MS = 86_400_000;

function dayOf(date: string): number {
    return Date.parse(`${date}T00:00:00Z`);
}

/**
 * The calendar window: from 1 January of last year to 31 December of the year of the latest
 * end date (at least next year), so the today marker always sits inside it.
 */
function calendarWindow(rows: CalendarRow[]): {
    start: number;
    end: number;
    years: number[];
} {
    const currentYear = new Date().getUTCFullYear();
    const dates = rows.flatMap((row) =>
        row.runtimes.flatMap((runtime) =>
            [runtime.activeSupportEndsOn, runtime.securitySupportEndsOn].filter(
                (date): date is string => date !== null,
            ),
        ),
    );
    const lastYear = Math.max(
        currentYear + 1,
        ...dates.map((date) => Number(date.slice(0, 4))),
    );
    const years = Array.from(
        { length: lastYear - currentYear + 2 },
        (_, offset) => currentYear - 1 + offset,
    );

    return {
        start: Date.UTC(currentYear - 1, 0, 1),
        end: Date.UTC(lastYear, 11, 31) + DAY_MS,
        years,
    };
}

/**
 * End-of-life calendar (#23): one group of rows per project, soonest end first, with bars for
 * active support (until its end) and security support (from the end of active support), and a
 * today marker. The dates and status are also in their own columns: the bars are decorative.
 */
function CalendarView({ rows }: { rows: CalendarRow[] }) {
    const { t } = useTranslation();
    const timeline = useMemo(() => calendarWindow(rows), [rows]);

    if (rows.length === 0) {
        return (
            <PageEmptyState
                icon={CalendarClock}
                title={t('fleet.eol.emptyTitle')}
                description={t('fleet.eol.emptyDescription')}
            />
        );
    }

    const span = timeline.end - timeline.start;
    const percent = (time: number): number =>
        Math.min(100, Math.max(0, ((time - timeline.start) / span) * 100));
    const today = percent(Date.now());

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                <p className="max-w-2xl">{t('fleet.eol.description')}</p>
                <span className="inline-flex items-center gap-1.5">
                    <span
                        className="h-2.5 w-4 rounded-sm bg-primary"
                        aria-hidden
                    />
                    {t('fleet.eol.active')}
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span
                        className="h-2.5 w-4 rounded-sm border border-primary bg-primary/30"
                        aria-hidden
                    />
                    {t('fleet.eol.security')}
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="h-3 w-0.5 bg-foreground" aria-hidden />
                    {t('fleet.eol.today')}
                </span>
            </div>
            <TablePanel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('fleet.project')}</TableHead>
                            <TableHead>{t('report.runtime.name')}</TableHead>
                            <TableHead>{t('fleet.eol.activeUntil')}</TableHead>
                            <TableHead>
                                {t('report.runtime.securityUntil')}
                            </TableHead>
                            <TableHead>{t('fleet.status')}</TableHead>
                            <TableHead className="w-1/2 min-w-80">
                                <div className="relative h-4" aria-hidden>
                                    {timeline.years.map((year) => (
                                        <span
                                            key={year}
                                            className="absolute -translate-x-1/2 text-xs font-normal text-muted-foreground tabular-nums"
                                            style={{
                                                left: `${percent(Date.UTC(year, 0, 1))}%`,
                                            }}
                                        >
                                            {year}
                                        </span>
                                    ))}
                                </div>
                                <span className="sr-only">
                                    {t('fleet.eol.timeline')}
                                </span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <Fragment key={row.project.slug}>
                                {row.runtimes.map((runtime, position) => {
                                    const activeEnd =
                                        runtime.activeSupportEndsOn !== null
                                            ? percent(
                                                  dayOf(
                                                      runtime.activeSupportEndsOn,
                                                  ),
                                              )
                                            : null;
                                    const securityEnd =
                                        runtime.securitySupportEndsOn !== null
                                            ? percent(
                                                  dayOf(
                                                      runtime.securitySupportEndsOn,
                                                  ),
                                              )
                                            : null;
                                    const isEnded =
                                        supportStatus(
                                            runtime.securitySupportEndsOn,
                                        ) === 'ended';

                                    return (
                                        <TableRow
                                            key={runtime.name}
                                            className={cn(
                                                position > 0 && 'border-t-0',
                                            )}
                                        >
                                            {position === 0 ? (
                                                <TableCell
                                                    rowSpan={
                                                        row.runtimes.length
                                                    }
                                                    className="align-top"
                                                >
                                                    <ProjectLink
                                                        project={row.project}
                                                    />
                                                </TableCell>
                                            ) : null}
                                            <TableCell>
                                                {t(
                                                    `report.runtime.${runtime.name}`,
                                                )}{' '}
                                                <span className="font-mono text-xs">
                                                    {runtime.version ??
                                                        runtime.cycle ??
                                                        '—'}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-xs tabular-nums">
                                                {runtime.activeSupportEndsOn ??
                                                    '—'}
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'text-xs tabular-nums',
                                                    isEnded &&
                                                        'text-destructive',
                                                )}
                                            >
                                                {runtime.securitySupportEndsOn ??
                                                    '—'}
                                            </TableCell>
                                            <TableCell>
                                                <RuntimeSupportBadge
                                                    runtime={{
                                                        version:
                                                            runtime.version,
                                                        cycle: runtime.cycle,
                                                        security_support_ends_on:
                                                            runtime.securitySupportEndsOn,
                                                        active_support_ends_on:
                                                            runtime.activeSupportEndsOn,
                                                    }}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div
                                                    className="relative h-4 rounded-sm bg-muted"
                                                    aria-hidden
                                                >
                                                    {activeEnd !== null ? (
                                                        <span
                                                            className="absolute inset-y-0 left-0 rounded-l-sm bg-primary"
                                                            style={{
                                                                width: `${activeEnd}%`,
                                                            }}
                                                        />
                                                    ) : null}
                                                    {securityEnd !== null &&
                                                    securityEnd >
                                                        (activeEnd ?? 0) ? (
                                                        <span
                                                            className="absolute inset-y-0 rounded-r-sm border border-primary bg-primary/30"
                                                            style={{
                                                                left: `${activeEnd ?? 0}%`,
                                                                width: `${securityEnd - (activeEnd ?? 0)}%`,
                                                            }}
                                                        />
                                                    ) : null}
                                                    <span
                                                        className="absolute -inset-y-1 w-0.5 bg-foreground"
                                                        style={{
                                                            left: `${today}%`,
                                                        }}
                                                    />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            </TablePanel>
        </div>
    );
}
