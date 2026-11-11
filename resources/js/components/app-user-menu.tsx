import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { NavUser } from '@/components/nav-user';
import { DropdownMenuCheckboxItem } from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAccessibilityPreferences } from '@/hooks/use-accessibility-preferences';
import { useAppearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import i18n from '@/lib/i18n';
import { logout } from '@/routes';
import { update as updateLocale } from '@/routes/locale';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

/**
 * Excubia's user menu on the registry `nav-user` item (app-ui): profile settings, language
 * (saved on the server, then applied to i18next and `<html lang>`), appearance, accessibility,
 * the list preferences (compact tables, single-key shortcuts; #277) and logout. The registry components are generic; this wires them to Excubia's routes, hooks
 * and shared props.
 */
function AppUserMenuContent({ user }: { user: User }) {
    const cleanup = useMobileNavigation();
    const { locale, availableLocales } = usePage().props;
    const { appearance, updateAppearance } = useAppearance();
    const {
        highContrastEnabled,
        reduceMotionEnabled,
        toggleHighContrast,
        toggleReduceMotion,
        compactTablesEnabled,
        singleKeyShortcutsDisabled,
        toggleCompactTables,
        toggleSingleKeyShortcuts,
    } = useAccessibilityPreferences();
    const { t } = useTranslation();

    const changeLocale = (next: string): void => {
        if (next === locale) {
            return;
        }

        router.patch(
            updateLocale.url(),
            { locale: next },
            {
                preserveScroll: true,
                onSuccess: () => {
                    void i18n.changeLanguage(next).then(() => {
                        document.documentElement.lang = next.replace('_', '-');
                    });
                },
            },
        );
    };

    return (
        <UserMenuContent
            user={user}
            settingsHref={edit()}
            logoutHref={logout()}
            onNavigate={cleanup}
            // Drop the Inertia page cache so the next user never sees this one's pages.
            onLogout={() => router.flushAll()}
            locale={{
                current: locale,
                options: availableLocales,
                onChange: changeLocale,
            }}
            appearance={{ value: appearance, onChange: updateAppearance }}
            accessibility={{
                highContrast: highContrastEnabled,
                reduceMotion: reduceMotionEnabled,
                onToggleHighContrast: toggleHighContrast,
                onToggleReduceMotion: toggleReduceMotion,
            }}
        >
            {/* Personal list preferences (#277): table density and single-key shortcuts. */}
            <DropdownMenuCheckboxItem
                checked={compactTablesEnabled}
                onCheckedChange={toggleCompactTables}
                onSelect={(event) => event.preventDefault()}
                data-test="preference-compact-tables"
            >
                {t('userMenu.compactTables')}
            </DropdownMenuCheckboxItem>
            <DropdownMenuCheckboxItem
                checked={!singleKeyShortcutsDisabled}
                onCheckedChange={toggleSingleKeyShortcuts}
                onSelect={(event) => event.preventDefault()}
                data-test="preference-single-key-shortcuts"
            >
                {t('userMenu.singleKeyShortcuts')}
            </DropdownMenuCheckboxItem>
        </UserMenuContent>
    );
}

/**
 * The sidebar user menu for the signed-in user. Excubia has no realtime connection, so the
 * avatar shows no connection dot.
 */
export function AppNavUser() {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    return (
        <NavUser user={auth.user}>
            <AppUserMenuContent user={auth.user} />
        </NavUser>
    );
}
