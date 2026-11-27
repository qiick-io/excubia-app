import { Link } from '@inertiajs/react';
import {
    Accessibility,
    Check,
    Languages,
    LogOut,
    Settings,
    SunMoon,
} from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import type { NavUserData } from '@/components/user-info';

type Href = ComponentProps<typeof Link>['href'];

export type UserMenuAppearance = 'light' | 'dark' | 'system';

const appearanceOptions: UserMenuAppearance[] = ['light', 'dark', 'system'];

type UserMenuContentProps = {
    user: NavUserData;
    /** Profile settings page (a URL or a Wayfinder route). */
    settingsHref: Href;
    /** Logout route, sent as POST. */
    logoutHref: Href;
    /** Runs before any link is followed (starter kit `useMobileNavigation()`). */
    onNavigate?: () => void;
    /** Extra cleanup before logout (stores, `router.flushAll()`), after `onNavigate`. */
    onLogout?: () => void;
    /** Language submenu; hidden without it. */
    locale?: {
        current: string;
        /** Locale code → label, e.g. `{ en: 'English', it: 'Italiano' }`. */
        options: Record<string, string>;
        onChange: (code: string) => void;
    };
    /** Appearance submenu (starter kit `useAppearance()`); hidden without it. */
    appearance?: {
        value: UserMenuAppearance;
        onChange: (mode: UserMenuAppearance) => void;
    };
    /** Accessibility submenu (high contrast, reduce motion); hidden without it. */
    accessibility?: {
        highContrast: boolean;
        reduceMotion: boolean;
        onToggleHighContrast: () => void;
        onToggleReduceMotion: () => void;
    };
    /** Extra items at the end of the settings group. */
    children?: ReactNode;
};

/**
 * Dropdown menu body for profile settings, language, appearance,
 * accessibility and logout (externa-core `user-menu-content.tsx`). Every item
 * shares one left edge: icon column, then label.
 */
export function UserMenuContent({
    user,
    settingsHref,
    logoutHref,
    onNavigate,
    onLogout,
    locale,
    appearance,
    accessibility,
    children,
}: UserMenuContentProps) {
    const { t } = useTranslation();

    const handleLogout = () => {
        onNavigate?.();
        onLogout?.();
    };

    const enabledAccessibilityCount = accessibility
        ? Number(accessibility.highContrast) +
          Number(accessibility.reduceMotion)
        : 0;
    const accessibilitySummary =
        enabledAccessibilityCount === 0
            ? t('userMenu.accessibilityOff')
            : t('userMenu.accessibilityOnCount', {
                  count: enabledAccessibilityCount,
              });

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-2 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={settingsHref}
                        prefetch
                        onClick={onNavigate}
                    >
                        <Settings />
                        {t('userMenu.settings')}
                    </Link>
                </DropdownMenuItem>
                {locale ? (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger className="cursor-pointer">
                            <Languages className="size-4" />
                            {t('userMenu.language')}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            {Object.entries(locale.options).map(
                                ([code, label]) => (
                                    <DropdownMenuItem
                                        key={code}
                                        className="cursor-pointer"
                                        onSelect={() => {
                                            if (code !== locale.current) {
                                                locale.onChange(code);
                                            }
                                        }}
                                    >
                                        <span className="flex-1">{label}</span>
                                        {locale.current === code ? (
                                            <Check className="size-4" />
                                        ) : null}
                                    </DropdownMenuItem>
                                ),
                            )}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                ) : null}
                {appearance ? (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger className="cursor-pointer">
                            <SunMoon className="size-4" />
                            {t('userMenu.appearance')}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            {appearanceOptions.map((mode) => (
                                <DropdownMenuItem
                                    key={mode}
                                    className="cursor-pointer"
                                    onSelect={() => appearance.onChange(mode)}
                                >
                                    <span className="flex-1">
                                        {t(`settings.appearance.${mode}`)}
                                    </span>
                                    {appearance.value === mode ? (
                                        <Check className="size-4" />
                                    ) : null}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                ) : null}
                {accessibility ? (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger
                            className="cursor-pointer"
                            data-test="accessibility-menu"
                        >
                            <Accessibility className="size-4" />
                            <span className="flex-1">
                                {t('userMenu.accessibility')}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {accessibilitySummary}
                            </span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuItem
                                className="cursor-pointer"
                                data-test="accessibility-high-contrast"
                                onSelect={(event) => {
                                    event.preventDefault();
                                    accessibility.onToggleHighContrast();
                                }}
                            >
                                <span className="flex-1">
                                    {t('userMenu.highContrast')}
                                </span>
                                {accessibility.highContrast ? (
                                    <Check className="size-4" />
                                ) : null}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="cursor-pointer"
                                data-test="accessibility-reduce-motion"
                                onSelect={(event) => {
                                    event.preventDefault();
                                    accessibility.onToggleReduceMotion();
                                }}
                            >
                                <span className="flex-1">
                                    {t('userMenu.reduceMotion')}
                                </span>
                                {accessibility.reduceMotion ? (
                                    <Check className="size-4" />
                                ) : null}
                            </DropdownMenuItem>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                ) : null}
                {children}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="w-full cursor-pointer"
                    href={logoutHref}
                    method="post"
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut />
                    {t('userMenu.logOut')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
