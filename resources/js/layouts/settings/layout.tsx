import { Link, usePage } from '@inertiajs/react';
import { Bug } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editApplication } from '@/routes/application-settings';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import roles from '@/routes/roles';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const DEFAULT_REPORT_BUG_URL =
    'https://github.com/qiick-io/excubia-app/issues/new?template=bug_report.yml';

type SettingsLayoutProps = PropsWithChildren<{
    /** Wider content pane for tables / matrices (Access pages). */
    wide?: boolean;
}>;

/**
 * Settings section layout with sidebar nav (client-only to avoid SSR mismatch).
 */
export default function SettingsLayout({
    children,
    wide = false,
}: SettingsLayoutProps) {
    const { t } = useTranslation();
    const { isCurrentUrl } = useCurrentUrl();
    const { can } = useCan();
    const { projectSettings } = usePage().props;

    const accountNavItems: NavItem[] = [
        {
            title: t('settings.layout.profile'),
            href: edit(),
            icon: null,
        },
        {
            title: t('settings.layout.security'),
            href: editSecurity(),
            icon: null,
        },
        {
            title: t('settings.layout.appearance'),
            href: editAppearance(),
            icon: null,
        },
    ];

    const projectNavItems: NavItem[] = can(PermissionEnum.SettingsManage)
        ? [
              {
                  title: t('settings.layout.general'),
                  href: editApplication(),
                  icon: null,
              },
          ]
        : [];

    const accessNavItems: NavItem[] = can(PermissionEnum.RolesManage)
        ? [
              {
                  title: t('settings.layout.roles'),
                  href: roles.index.url(),
                  icon: null,
              },
          ]
        : [];

    const reportBugUrl =
        projectSettings?.reportBugUrl?.trim() || DEFAULT_REPORT_BUG_URL;

    if (typeof window === 'undefined') {
        return null;
    }

    const renderNavGroup = (label: string, items: NavItem[]) => (
        <SidebarGroup key={label} className="p-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item, index) => (
                    <SidebarMenuItem key={`${toUrl(item.href)}-${index}`}>
                        <SidebarMenuButton
                            asChild
                            size="sm"
                            isActive={isCurrentUrl(item.href)}
                        >
                            <Link href={item.href}>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );

    return (
        // App shell is h-svh + overflow-hidden; only the content pane scrolls.
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden px-4 py-6">
            <Heading
                title={t('settings.layout.title')}
                description={t('settings.layout.description')}
            />

            <div className="flex min-h-0 flex-1 flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl shrink-0 lg:w-48">
                    <nav
                        className="flex flex-col gap-2"
                        aria-label={t('settings.layout.navAria')}
                    >
                        {renderNavGroup(
                            t('settings.layout.sectionAccount'),
                            accountNavItems,
                        )}

                        {projectNavItems.length > 0
                            ? renderNavGroup(
                                  t('settings.layout.sectionProject'),
                                  projectNavItems,
                              )
                            : null}

                        {accessNavItems.length > 0
                            ? renderNavGroup(
                                  t('settings.layout.sectionAccess'),
                                  accessNavItems,
                              )
                            : null}

                        <SidebarGroup className="p-0">
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton asChild size="sm">
                                        <a
                                            href={reportBugUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Bug />
                                            <span>
                                                {t('settings.layout.reportBug')}
                                            </span>
                                        </a>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroup>
                    </nav>
                </aside>

                <Separator className="my-6 shrink-0 lg:hidden" />

                {/* Scroll pane: sticky Save bars pin to this box, not the viewport. */}
                <div
                    className={cn(
                        'min-h-0 flex-1 overflow-y-auto',
                        wide ? 'md:max-w-4xl' : 'md:max-w-2xl',
                    )}
                >
                    <section
                        className={cn(
                            'space-y-12 pb-6',
                            wide ? 'max-w-4xl' : 'max-w-xl',
                        )}
                    >
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
