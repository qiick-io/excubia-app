import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { ProjectAvatar } from '@/components/projects/project-avatar';
import { STRING_LIMITS } from '@/lib/string-limits';
import projects from '@/routes/projects';
import type { ProjectRow, ProjectVisibility } from '@/types';

type ProjectFormData = {
    name: string;
    is_enabled: boolean;
    visibility: ProjectVisibility;
    /** A newly picked logo (#287), uploaded with the form. */
    logo: File | null;
    /** Back to the initials avatar: the saved logo is deleted. */
    remove_logo: boolean;
};

const EMPTY_PROJECT_FORM: ProjectFormData = {
    name: '',
    is_enabled: true,
    visibility: 'everyone',
    logo: null,
    remove_logo: false,
};

/** File types and size of the logo, as validated by ValidatesProjectLogoTrait. */
const LOGO_ACCEPT = 'image/png,image/jpeg,image/webp,image/svg+xml';

export type ProjectFormDrawerProps = {
    editing: ProjectRow | null;
    readOnly?: boolean;
    /** Admins may restrict a project to admins (#70). */
    canChangeVisibility?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for registering and editing a project. A new project gets its push token from
 * the server, shown by PushTokenDialog. The system project (#70) keeps its name. The optional
 * logo (#287) is uploaded with the form, so the request is multipart when a file is picked.
 */
export function ProjectFormDrawer({
    editing,
    readOnly = false,
    canChangeVisibility = false,
    open = true,
    onCancel,
    onSuccess,
}: ProjectFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm<ProjectFormData>({ ...EMPTY_PROJECT_FORM });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload: ProjectFormData = {
                name: editing.name,
                is_enabled: editing.is_enabled,
                visibility: editing.visibility,
                logo: null,
                remove_logo: false,
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_PROJECT_FORM });
            form.reset();
        }

        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, open]);

    const submit = (): void => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        // The server refuses `visibility` from users who may not change it. A file only travels
        // in a POST body, so an update is a POST with Laravel's `_method` spoofing.
        form.transform(({ visibility, logo, remove_logo, ...data }) => ({
            ...data,
            ...(canChangeVisibility ? { visibility } : {}),
            ...(logo ? { logo } : {}),
            ...(remove_logo ? { remove_logo } : {}),
            ...(editing ? { _method: 'put' } : {}),
        }));

        form.post(
            editing ? projects.update.url(editing.id) : projects.store.url(),
            options,
        );
    };

    const nameError =
        form.errors.name ?? (form.errors as Record<string, string>).slug;

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>
                    {editing
                        ? t('projects.editProject')
                        : t('projects.newProject')}
                </DrawerTitle>
                <DrawerDescription>
                    {t('projects.formDescription')}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();

                    if (!readOnly) {
                        submit();
                    }
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field data-invalid={nameError ? true : undefined}>
                            <FieldLabel htmlFor="project_name">
                                {t('common.name')}
                            </FieldLabel>
                            <Input
                                id="project_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.PROJECT_NAME}
                                disabled={readOnly || editing?.is_system}
                                aria-invalid={nameError ? true : undefined}
                                aria-describedby={
                                    nameError ? 'project-name-error' : undefined
                                }
                            />
                            {editing ? (
                                <FieldDescription>
                                    {t('projects.slugHint', {
                                        slug: editing.slug,
                                    })}
                                </FieldDescription>
                            ) : null}
                            <FieldError id="project-name-error">
                                {nameError}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="project_is_enabled"
                                    checked={form.data.is_enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_enabled',
                                            checked === true,
                                        )
                                    }
                                    disabled={readOnly}
                                />
                                <Label htmlFor="project_is_enabled">
                                    {t('projects.enabled')}
                                </Label>
                            </div>
                            <FieldDescription>
                                {t('projects.enabledHint')}
                            </FieldDescription>
                        </Field>

                        <ProjectLogoField
                            slug={
                                editing?.slug ?? approximateSlug(form.data.name)
                            }
                            name={form.data.name}
                            savedLogoUrl={
                                form.data.remove_logo
                                    ? null
                                    : (editing?.logo_url ?? null)
                            }
                            pickedLogo={form.data.logo}
                            readOnly={readOnly}
                            error={form.errors.logo}
                            onPick={(file) => {
                                form.setData((data) => ({
                                    ...data,
                                    logo: file,
                                    remove_logo: false,
                                }));
                            }}
                            onRemove={() => {
                                form.setData((data) => ({
                                    ...data,
                                    logo: null,
                                    remove_logo: editing?.logo_url != null,
                                }));
                            }}
                        />

                        {canChangeVisibility ? (
                            <Field>
                                <FieldLabel htmlFor="project_visibility">
                                    {t('projects.visibility.label')}
                                </FieldLabel>
                                <NativeSelect
                                    id="project_visibility"
                                    value={form.data.visibility}
                                    onChange={(event) =>
                                        form.setData(
                                            'visibility',
                                            event.target
                                                .value as ProjectVisibility,
                                        )
                                    }
                                    disabled={readOnly}
                                >
                                    <NativeSelectOption value="everyone">
                                        {t('projects.visibility.everyone')}
                                    </NativeSelectOption>
                                    <NativeSelectOption value="admins">
                                        {t('projects.visibility.admins')}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <FieldDescription>
                                    {t('projects.visibility.hint')}
                                </FieldDescription>
                            </Field>
                        ) : null}
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing ? t('common.save') : t('projects.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}

/** The server's slug (`Str::slug`) closely enough to preview a new project's colour. */
function approximateSlug(name: string): string {
    return name
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * The project logo (#287), laid out like the branding file fields of Settings → Appearance:
 * the preview (the avatar as it will look), then Choose / Change file and Remove. Removing goes
 * back to the initials.
 */
function ProjectLogoField({
    slug,
    name,
    savedLogoUrl,
    pickedLogo,
    readOnly,
    error,
    onPick,
    onRemove,
}: {
    slug: string;
    name: string;
    savedLogoUrl: string | null;
    pickedLogo: File | null;
    readOnly: boolean;
    error?: string;
    onPick: (file: File) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const input = useRef<HTMLInputElement>(null);
    const [pickedLogoUrl, setPickedLogoUrl] = useState<string | null>(null);

    // An object URL per picked file, released when it is replaced or the field unmounts.
    useEffect(() => {
        if (pickedLogo === null) {
            setPickedLogoUrl(null);

            return;
        }

        const objectUrl = URL.createObjectURL(pickedLogo);
        setPickedLogoUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [pickedLogo]);

    const previewUrl = pickedLogoUrl ?? savedLogoUrl;

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel id="project-logo-label">
                {t('projects.logo.label')}
            </FieldLabel>
            {/* The visible button opens this hidden input's dialog. */}
            <input
                ref={input}
                type="file"
                accept={LOGO_ACCEPT}
                hidden
                onChange={(event) => {
                    const file = event.target.files?.[0];

                    if (file) {
                        onPick(file);
                    }
                }}
            />
            <div className="flex items-center gap-3">
                <ProjectAvatar
                    slug={slug}
                    name={name}
                    logoUrl={previewUrl}
                    size="lg"
                />
                {readOnly ? null : (
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            // Visible text first, then the field: "Change file Logo".
                            aria-labelledby="project-logo-choose project-logo-label"
                            aria-invalid={error ? true : undefined}
                            aria-describedby={
                                error
                                    ? 'project-logo-hint project-logo-error'
                                    : 'project-logo-hint'
                            }
                            onClick={() => input.current?.click()}
                        >
                            <span id="project-logo-choose">
                                {previewUrl
                                    ? t('projects.logo.change')
                                    : t('projects.logo.choose')}
                            </span>
                        </Button>
                        {previewUrl ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    if (input.current) {
                                        input.current.value = '';
                                    }

                                    onRemove();
                                }}
                            >
                                {t('projects.logo.remove')}
                            </Button>
                        ) : null}
                    </div>
                )}
            </div>
            <FieldDescription id="project-logo-hint">
                {t('projects.logo.hint')}
            </FieldDescription>
            <FieldError id="project-logo-error">{error}</FieldError>
        </Field>
    );
}
