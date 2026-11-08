import { Form, Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppearanceSettingsController from '@/actions/App/Http/Controllers/Settings/AppearanceSettingsController';
import Heading from '@/components/heading';
import { BrandingFileField } from '@/components/settings/branding-file-field';
import { ThemePresetField } from '@/components/settings/theme-preset-field';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { brandPassesAa } from '@/lib/brand-contrast';
import { applyThemeCss, buildPreviewThemeCss } from '@/lib/theme-css';
import { edit as editAppearance } from '@/routes/appearance';
import type {
    AppearanceSettings,
    BreadcrumbItem,
    ProjectAppearance,
    ThemePresetSlug,
} from '@/types';
import { edit as editProfile } from '@/routes/profile';

/**
 * Re-apply the saved theme CSS (shared Inertia props) over the live preview.
 */
function restoreThemeFromAppearance(
    appearance: ProjectAppearance | undefined,
): void {
    if (appearance?.themeCss) {
        applyThemeCss(appearance.themeCss);
    }
}

/**
 * Project theme preset, optional brand colours and default theme settings.
 * The logo and favicon fields upload straight to the app (no file manager, unlike externa-core).
 */
export default function Appearance({
    appearance,
}: {
    appearance: AppearanceSettings;
}) {
    const { t } = useTranslation();
    const { projectAppearance } = usePage().props;

    const initialThemePreset = appearance.theme_preset;
    const initialColor = appearance.project_color;
    const initialColorDark = appearance.project_color_dark;
    const initialAppearance = appearance.default_appearance;
    const [themePreset, setThemePresetState] =
        useState<ThemePresetSlug>(initialThemePreset);
    const [projectColor, setProjectColor] = useState<string | null>(
        initialColor,
    );
    const [projectColorDark, setProjectColorDark] = useState<string | null>(
        initialColorDark,
    );
    const [defaultAppearance, setDefaultAppearance] =
        useState(initialAppearance);

    const selectedPreset =
        appearance.theme_presets.find(
            (preset) => preset.slug === themePreset,
        ) ?? appearance.theme_presets[0];
    const displayColor = projectColor ?? selectedPreset.swatchLight;
    const displayColorDark =
        projectColorDark ?? projectColor ?? selectedPreset.swatchDark;
    const hasCustomColor = Boolean(projectColor || projectColorDark);
    const showsContrastWarning =
        hasCustomColor && !brandPassesAa(projectColor, projectColorDark);

    const setThemePreset = (value: ThemePresetSlug): void => {
        setThemePresetState(value);
        // Picking a preset clears the custom hex so the whole palette applies.
        setProjectColor(null);
        setProjectColorDark(null);
    };

    // Live preview of the draft; the cleanup restores the latest saved theme.
    useEffect(() => {
        applyThemeCss(
            buildPreviewThemeCss(
                selectedPreset,
                projectColor,
                projectColorDark,
            ),
        );

        return () => {
            restoreThemeFromAppearance(projectAppearance);
        };
    }, [selectedPreset, projectColor, projectColorDark, projectAppearance]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: editProfile() },
        {
            title: t('settings.layout.appearance'),
            href: editAppearance(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.appearance.head')} />

            <h1 className="sr-only">{t('settings.appearance.head')}</h1>

            <SettingsLayout>
                <Form
                    {...AppearanceSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.appearance.themePresetTitle',
                                    )}
                                    description={t(
                                        'settings.appearance.themePresetDescription',
                                    )}
                                />

                                <ThemePresetField
                                    catalog={appearance.theme_presets}
                                    value={themePreset}
                                    onChange={setThemePreset}
                                    error={errors.theme_preset}
                                />
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.appearance.brandingTitle',
                                    )}
                                    description={t(
                                        'settings.appearance.brandingDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">
                                            {t(
                                                'settings.appearance.customColorTitle',
                                            )}
                                        </p>
                                        <FieldDescription>
                                            {t(
                                                'settings.appearance.customColorDescription',
                                            )}
                                        </FieldDescription>
                                    </div>

                                    <Field
                                        data-invalid={
                                            errors.project_color
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="project_color">
                                            {t(
                                                'settings.appearance.projectColor',
                                            )}
                                        </FieldLabel>
                                        <InputGroup className="max-w-[220px] bg-transparent has-[:focus-visible]:border-ring has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background dark:border-white/25 dark:bg-transparent">
                                            <InputGroupAddon
                                                align="inline-start"
                                                className="pl-2"
                                            >
                                                <Input
                                                    id="project_color"
                                                    type="color"
                                                    className="h-7 w-7 shrink-0 cursor-pointer border-0 p-0 shadow-none focus-visible:ring-0 dark:bg-transparent [&::-webkit-color-swatch]:rounded-[3px] [&::-webkit-color-swatch]:border-0 [&::-webkit-color-swatch-wrapper]:p-0"
                                                    value={displayColor}
                                                    onChange={(event) =>
                                                        setProjectColor(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                name="project_color"
                                                value={projectColor ?? ''}
                                                onChange={(event) =>
                                                    setProjectColor(
                                                        event.target.value ||
                                                            null,
                                                    )
                                                }
                                                className="font-mono"
                                                placeholder={
                                                    selectedPreset.swatchLight
                                                }
                                                aria-invalid={
                                                    errors.project_color
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.project_color
                                                        ? 'project-color-error'
                                                        : showsContrastWarning
                                                          ? 'brand-contrast-warning'
                                                          : undefined
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError id="project-color-error">
                                            {errors.project_color}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.project_color_dark
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="project_color_dark">
                                            {t(
                                                'settings.appearance.projectColorDark',
                                            )}
                                        </FieldLabel>
                                        <InputGroup className="max-w-[220px] bg-transparent has-[:focus-visible]:border-ring has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background dark:border-white/25 dark:bg-transparent">
                                            <InputGroupAddon
                                                align="inline-start"
                                                className="pl-2"
                                            >
                                                <Input
                                                    id="project_color_dark"
                                                    type="color"
                                                    className="h-7 w-7 shrink-0 cursor-pointer border-0 p-0 shadow-none focus-visible:ring-0 dark:bg-transparent [&::-webkit-color-swatch]:rounded-[3px] [&::-webkit-color-swatch]:border-0 [&::-webkit-color-swatch-wrapper]:p-0"
                                                    value={displayColorDark}
                                                    onChange={(event) =>
                                                        setProjectColorDark(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                name="project_color_dark"
                                                value={projectColorDark ?? ''}
                                                onChange={(event) =>
                                                    setProjectColorDark(
                                                        event.target.value ||
                                                            null,
                                                    )
                                                }
                                                aria-label={t(
                                                    'settings.appearance.projectColorDark',
                                                )}
                                                className="font-mono"
                                                placeholder={
                                                    selectedPreset.swatchDark
                                                }
                                                aria-invalid={
                                                    errors.project_color_dark
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.project_color_dark
                                                        ? 'project-color-dark-error'
                                                        : undefined
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError id="project-color-dark-error">
                                            {errors.project_color_dark}
                                        </FieldError>
                                    </Field>

                                    {hasCustomColor ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="w-fit"
                                            onClick={() => {
                                                setProjectColor(null);
                                                setProjectColorDark(null);
                                            }}
                                        >
                                            {t(
                                                'settings.appearance.clearCustomColor',
                                            )}
                                        </Button>
                                    ) : null}

                                    {showsContrastWarning ? (
                                        <p
                                            id="brand-contrast-warning"
                                            role="status"
                                            className="text-sm text-warning"
                                        >
                                            {t(
                                                'settings.appearance.brandContrastWarning',
                                            )}
                                        </p>
                                    ) : null}

                                    {(
                                        [
                                            [
                                                'project_logo',
                                                'settings.appearance.projectLogo',
                                                'image/png,image/jpeg,image/webp,image/svg+xml',
                                                appearance.branding.logoUrl,
                                            ],
                                            [
                                                'project_logo_dark',
                                                'settings.appearance.projectLogoDark',
                                                'image/png,image/jpeg,image/webp,image/svg+xml',
                                                appearance.branding.logoDarkUrl,
                                            ],
                                            [
                                                'public_favicon',
                                                'settings.appearance.publicFavicon',
                                                'image/x-icon,image/vnd.microsoft.icon,image/png,image/svg+xml,.ico',
                                                appearance.branding.faviconUrl,
                                            ],
                                        ] as const
                                    ).map(
                                        ([
                                            field,
                                            labelKey,
                                            accept,
                                            savedUrl,
                                        ]) => (
                                            <BrandingFileField
                                                // Remounts after a save, with the new file.
                                                key={`${field}-${savedUrl ?? 'none'}`}
                                                name={field}
                                                label={t(labelKey)}
                                                accept={accept}
                                                savedUrl={savedUrl}
                                                error={errors[field]}
                                            />
                                        ),
                                    )}
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.appearance.defaultThemeTitle',
                                    )}
                                    description={t(
                                        'settings.appearance.defaultThemeDescription',
                                    )}
                                />

                                <FieldGroup>
                                    <Field
                                        data-invalid={
                                            errors.default_appearance
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="default_appearance">
                                            {t(
                                                'settings.appearance.defaultAppearance',
                                            )}
                                        </FieldLabel>
                                        <input
                                            type="hidden"
                                            name="default_appearance"
                                            value={defaultAppearance}
                                        />
                                        <Select
                                            value={defaultAppearance}
                                            onValueChange={(value) => {
                                                if (
                                                    value === 'system' ||
                                                    value === 'light' ||
                                                    value === 'dark'
                                                ) {
                                                    setDefaultAppearance(value);
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                id="default_appearance"
                                                className="w-full"
                                                aria-invalid={
                                                    errors.default_appearance
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.default_appearance
                                                        ? 'default-appearance-error'
                                                        : undefined
                                                }
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="system">
                                                    {t(
                                                        'settings.appearance.system',
                                                    )}
                                                </SelectItem>
                                                <SelectItem value="light">
                                                    {t(
                                                        'settings.appearance.light',
                                                    )}
                                                </SelectItem>
                                                <SelectItem value="dark">
                                                    {t(
                                                        'settings.appearance.dark',
                                                    )}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FieldError id="default-appearance-error">
                                            {errors.default_appearance}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="appearance-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
