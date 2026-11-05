import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';

type Preview = { url: string; name: string | null };

/**
 * A logo or favicon field of Settings → Appearance, laid out as externa-core's (preview, then
 * Choose / Change file and Clear). externa-core picks from its file manager; Excubia has none,
 * so "Choose file" opens the browser's file dialog and the file is uploaded with the form.
 * Clearing sends `remove_files[]` so the saved file is deleted.
 */
export function BrandingFileField({
    name,
    label,
    accept,
    savedUrl,
    error,
}: {
    /** Upload field and setting key, e.g. `project_logo`. */
    name: string;
    label: string;
    /** Accepted file types for the dialog, matching UpdateAppearanceSettingsRequest. */
    accept: string;
    savedUrl: string | null;
    error?: string;
}) {
    const { t } = useTranslation();
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<Preview | null>(
        savedUrl ? { url: savedUrl, name: null } : null,
    );
    const [isRemoved, setIsRemoved] = useState(false);
    const errorId = `${name.replaceAll('_', '-')}-error`;

    // Release the object URL of a picked file when it is replaced or the field unmounts.
    useEffect(
        () => () => {
            if (preview && preview.url !== savedUrl) {
                URL.revokeObjectURL(preview.url);
            }
        },
        [preview, savedUrl],
    );

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel id={`${name}-label`}>{label}</FieldLabel>
            {/* The visible button opens this hidden input's dialog. */}
            <input
                ref={input}
                name={name}
                type="file"
                accept={accept}
                hidden
                onChange={(event) => {
                    const file = event.target.files?.[0];

                    if (file) {
                        setPreview({
                            url: URL.createObjectURL(file),
                            name: file.name,
                        });
                        setIsRemoved(false);
                    }
                }}
            />
            {isRemoved ? (
                <input type="hidden" name="remove_files[]" value={name} />
            ) : null}
            {preview ? (
                <div className="flex items-center gap-3 rounded-md border p-3">
                    <img
                        src={preview.url}
                        alt={label}
                        className="size-12 rounded object-contain"
                    />
                    {preview.name ? (
                        <span className="text-sm font-medium">
                            {preview.name}
                        </span>
                    ) : null}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('settings.appearance.noFile')}
                </p>
            )}
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    // Visible text first, then the field: "Change file Project logo".
                    aria-labelledby={`${name}-choose ${name}-label`}
                    onClick={() => input.current?.click()}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : undefined}
                >
                    <span id={`${name}-choose`}>
                        {preview
                            ? t('settings.appearance.changeFile')
                            : t('settings.appearance.chooseFile')}
                    </span>
                </Button>
                {preview ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            if (input.current) {
                                input.current.value = '';
                            }

                            setPreview(null);
                            setIsRemoved(true);
                        }}
                    >
                        {t('settings.appearance.clearFile')}
                    </Button>
                ) : null}
            </div>
            <FieldError id={errorId}>{error}</FieldError>
        </Field>
    );
}
