import { useTranslation } from 'react-i18next';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';

type ProfileLocaleFieldsProps = {
    /** Current locale code (user preference, or page fallback). */
    defaultLocale: string;
    /** Current IANA timezone (user preference, or UTC). */
    defaultTimezone: string;
    /** Locale code → label map from the shared Inertia props. */
    availableLocales: Record<string, string>;
    /** IANA timezone identifiers for the timezone select. */
    timezones: string[];
    /** Validation errors keyed by field name. */
    errors: Partial<Record<'locale' | 'timezone', string>>;
};

/**
 * Locale and timezone selects on the profile form (qiick shape: same fields as externa/instituta).
 */
export default function ProfileLocaleFields({
    defaultLocale,
    defaultTimezone,
    availableLocales,
    timezones,
    errors,
}: ProfileLocaleFieldsProps) {
    const { t } = useTranslation();

    return (
        <>
            <Field data-invalid={errors.locale ? true : undefined}>
                <FieldLabel htmlFor="locale">
                    {t('settings.profile.locale')}
                </FieldLabel>

                <NativeSelect
                    id="locale"
                    name="locale"
                    required
                    defaultValue={defaultLocale}
                    className="w-full"
                    aria-invalid={errors.locale ? true : undefined}
                    aria-describedby={
                        errors.locale ? 'locale-error' : undefined
                    }
                >
                    {Object.entries(availableLocales).map(([code, label]) => (
                        <NativeSelectOption key={code} value={code}>
                            {label}
                        </NativeSelectOption>
                    ))}
                </NativeSelect>

                <FieldError id="locale-error">{errors.locale}</FieldError>
            </Field>

            <Field data-invalid={errors.timezone ? true : undefined}>
                <FieldLabel htmlFor="timezone">
                    {t('settings.profile.timezone')}
                </FieldLabel>

                <NativeSelect
                    id="timezone"
                    name="timezone"
                    required
                    defaultValue={defaultTimezone}
                    className="w-full"
                    aria-invalid={errors.timezone ? true : undefined}
                    aria-describedby={
                        errors.timezone ? 'timezone-error' : undefined
                    }
                >
                    {timezones.map((timezone) => (
                        <NativeSelectOption key={timezone} value={timezone}>
                            {timezone}
                        </NativeSelectOption>
                    ))}
                </NativeSelect>

                <FieldError id="timezone-error">{errors.timezone}</FieldError>
            </Field>
        </>
    );
}
