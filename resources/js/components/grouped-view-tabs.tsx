import { Link, router } from '@inertiajs/react';
import { ChevronDown, OctagonAlert, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    NativeSelect,
    NativeSelectOptGroup,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

/**
 * Grouped view tabs (optional pattern `grouped-tabs`,
 * docs/optional/grouped-tabs.md).
 *
 * The core view tabs (docs/page.md) stop scaling past about six views: the
 * row overflows and nobody can tell where to look. This groups the views by
 * the question they answer and puts a count and a severity marker on each, so
 * the tabs also say where the problems are. Every view keeps its own URL; the
 * active group follows from the active view.
 *
 * Two accepted variants, picked once per app (`grouped-tabs:sub-menu` or
 * `grouped-tabs:two-rows` in qiick-standards.json):
 * - `sub-menu`: a group with several views opens a menu of them;
 * - `two-rows`: group tabs, then a second row with the active group's views.
 * Below `sm` both become one select of every view.
 */

export type ViewTabSeverity = 'warning' | 'critical';

export type ViewTab = {
    id: string;
    /** Already translated. */
    label: string;
    href: string;
    icon?: LucideIcon;
    /** Open items in this view; hidden when 0 or undefined. */
    count?: number;
    /** The worst open problem in this view; shown as an icon with a label. */
    severity?: ViewTabSeverity;
};

export type ViewTabGroup = {
    id: string;
    /** Already translated. A group with one view shows that view's label. */
    label: string;
    icon?: LucideIcon;
    views: ViewTab[];
};

type GroupedViewTabsProps = {
    groups: ViewTabGroup[];
    activeViewId: string;
    /** Names the views for screen readers ("Project views"). */
    ariaLabel: string;
    variant: 'sub-menu' | 'two-rows';
};

const SEVERITY_RANK: Record<ViewTabSeverity, number> = {
    warning: 1,
    critical: 2,
};

/** A group's count is the sum of its views; its severity the worst one. */
function summarize(views: ViewTab[]): {
    count: number;
    severity?: ViewTabSeverity;
} {
    let count = 0;
    let severity: ViewTabSeverity | undefined;

    for (const view of views) {
        count += view.count ?? 0;

        if (
            view.severity &&
            (!severity ||
                SEVERITY_RANK[view.severity] > SEVERITY_RANK[severity])
        ) {
            severity = view.severity;
        }
    }

    return { count, severity };
}

function SeverityMarker({ severity }: { severity?: ViewTabSeverity }) {
    const { t } = useTranslation();

    if (!severity) {
        return null;
    }

    const Icon = severity === 'critical' ? OctagonAlert : TriangleAlert;

    return (
        <>
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5',
                    severity === 'critical'
                        ? 'text-destructive'
                        : 'text-warning-text',
                )}
            />
            <span className="sr-only">
                {t(`viewTabs.severity.${severity}`)}
            </span>
        </>
    );
}

function CountBadge({ count }: { count: number }) {
    if (count === 0) {
        return null;
    }

    return (
        <Badge variant="secondary" className="h-5 px-1.5 tabular-nums">
            {count}
        </Badge>
    );
}

/** Icon, label, count and severity: the inside of every tab and menu row. */
function TabLabel({
    icon: Icon,
    label,
    count,
    severity,
}: {
    icon?: LucideIcon;
    label: string;
    count: number;
    severity?: ViewTabSeverity;
}) {
    return (
        <>
            {Icon ? <Icon aria-hidden="true" /> : null}
            {label}
            <CountBadge count={count} />
            <SeverityMarker severity={severity} />
        </>
    );
}

const TABS_ROW_CLASS = 'hidden overflow-x-auto border-b px-4 sm:block';

/**
 * The views of one record, grouped, under the page header.
 */
export function GroupedViewTabs({
    groups,
    activeViewId,
    ariaLabel,
    variant,
}: GroupedViewTabsProps) {
    const activeGroup =
        groups.find((group) =>
            group.views.some((view) => view.id === activeViewId),
        ) ?? groups[0];

    return (
        <>
            <MobileViewSelect
                groups={groups}
                activeViewId={activeViewId}
                ariaLabel={ariaLabel}
            />
            <Tabs
                value={activeGroup?.id}
                activationMode="manual"
                className={TABS_ROW_CLASS}
            >
                <TabsList variant="line" aria-label={ariaLabel}>
                    {groups.map((group) =>
                        variant === 'sub-menu' && group.views.length > 1 ? (
                            <GroupMenuTab
                                key={group.id}
                                group={group}
                                activeViewId={activeViewId}
                            />
                        ) : (
                            <GroupLinkTab
                                key={group.id}
                                group={group}
                                activeViewId={activeViewId}
                                isActive={group.id === activeGroup?.id}
                            />
                        ),
                    )}
                </TabsList>
            </Tabs>
            {variant === 'two-rows' &&
            activeGroup &&
            activeGroup.views.length > 1 ? (
                <Tabs
                    value={activeViewId}
                    activationMode="manual"
                    className={TABS_ROW_CLASS}
                >
                    <TabsList variant="line" aria-label={activeGroup.label}>
                        {activeGroup.views.map((view) => (
                            <TabsTrigger
                                key={view.id}
                                value={view.id}
                                asChild
                                aria-controls={undefined}
                            >
                                <Link
                                    href={view.href}
                                    aria-current={
                                        view.id === activeViewId
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    <TabLabel
                                        icon={view.icon}
                                        label={view.label}
                                        count={view.count ?? 0}
                                        severity={view.severity}
                                    />
                                </Link>
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
            ) : null}
        </>
    );
}

/**
 * A group as a plain link: a single view, or (two-rows) the group's entry,
 * which opens its active view or else its first one.
 */
function GroupLinkTab({
    group,
    activeViewId,
    isActive,
}: {
    group: ViewTabGroup;
    activeViewId: string;
    isActive: boolean;
}) {
    const targetView =
        group.views.find((view) => view.id === activeViewId) ?? group.views[0];
    const summary = summarize(group.views);
    const isSingleView = group.views.length === 1;

    if (!targetView) {
        return null;
    }

    return (
        <TabsTrigger value={group.id} asChild aria-controls={undefined}>
            <Link
                href={targetView.href}
                aria-current={isActive ? 'page' : undefined}
            >
                <TabLabel
                    icon={
                        isSingleView
                            ? (targetView.icon ?? group.icon)
                            : group.icon
                    }
                    label={isSingleView ? targetView.label : group.label}
                    count={summary.count}
                    severity={summary.severity}
                />
            </Link>
        </TabsTrigger>
    );
}

/** Sub-menu variant: the group tab opens a menu of its views. */
function GroupMenuTab({
    group,
    activeViewId,
}: {
    group: ViewTabGroup;
    activeViewId: string;
}) {
    const summary = summarize(group.views);
    const activeView = group.views.find((view) => view.id === activeViewId);

    return (
        <DropdownMenu>
            <TabsTrigger value={group.id} asChild aria-controls={undefined}>
                <DropdownMenuTrigger>
                    <TabLabel
                        icon={group.icon}
                        label={
                            activeView
                                ? `${group.label}: ${activeView.label}`
                                : group.label
                        }
                        count={summary.count}
                        severity={summary.severity}
                    />
                    <ChevronDown aria-hidden="true" className="size-3.5" />
                </DropdownMenuTrigger>
            </TabsTrigger>
            <DropdownMenuContent align="start">
                {group.views.map((view) => (
                    <DropdownMenuItem key={view.id} asChild>
                        <Link
                            href={view.href}
                            aria-current={
                                view.id === activeViewId ? 'page' : undefined
                            }
                        >
                            <TabLabel
                                icon={view.icon}
                                label={view.label}
                                count={view.count ?? 0}
                                severity={view.severity}
                            />
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Below `sm`: one select of every view, grouped, that navigates on change. */
function MobileViewSelect({
    groups,
    activeViewId,
    ariaLabel,
}: {
    groups: ViewTabGroup[];
    activeViewId: string;
    ariaLabel: string;
}) {
    const viewsById = new Map(
        groups.flatMap((group) => group.views).map((view) => [view.id, view]),
    );

    return (
        <div className="border-b px-4 py-2 sm:hidden">
            <NativeSelect
                aria-label={ariaLabel}
                value={activeViewId}
                className="w-full"
                onChange={(event) => {
                    const view = viewsById.get(event.target.value);

                    if (view) {
                        router.visit(view.href);
                    }
                }}
            >
                {groups.map((group) =>
                    group.views.length === 1 ? (
                        <NativeSelectOption
                            key={group.id}
                            value={group.views[0].id}
                        >
                            {optionLabel(group.views[0])}
                        </NativeSelectOption>
                    ) : (
                        <NativeSelectOptGroup
                            key={group.id}
                            label={group.label}
                        >
                            {group.views.map((view) => (
                                <NativeSelectOption
                                    key={view.id}
                                    value={view.id}
                                >
                                    {optionLabel(view)}
                                </NativeSelectOption>
                            ))}
                        </NativeSelectOptGroup>
                    ),
                )}
            </NativeSelect>
        </div>
    );
}

/** A select option can hold only text: the label and the count. */
const optionLabel = (view: ViewTab) =>
    view.count ? `${view.label} (${view.count})` : view.label;
