import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { useAccessibilityPreferences } from '@/hooks/use-accessibility-preferences';

/**
 * The signed-in user's list preferences (#277, #433): compact tables and single-key shortcuts,
 * each a labelled switch with what it does. They apply at once and live in this browser, like
 * the accessibility toggles; the user menu keeps navigation only.
 */
export function ListPreferences() {
    const { t } = useTranslation();
    const {
        compactTablesEnabled,
        singleKeyShortcutsDisabled,
        toggleCompactTables,
        toggleSingleKeyShortcuts,
    } = useAccessibilityPreferences();

    const preferences = [
        {
            id: 'preference-compact-tables',
            label: t('settings.preferences.compactTables'),
            description: t('settings.preferences.compactTablesDescription'),
            isChecked: compactTablesEnabled,
            onToggle: toggleCompactTables,
        },
        {
            id: 'preference-single-key-shortcuts',
            label: t('settings.preferences.singleKeyShortcuts'),
            description: t(
                'settings.preferences.singleKeyShortcutsDescription',
            ),
            isChecked: !singleKeyShortcutsDisabled,
            onToggle: toggleSingleKeyShortcuts,
        },
    ];

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.preferences.title')}
                description={t('settings.preferences.description')}
            />

            <FieldGroup className="gap-6">
                {preferences.map((preference) => (
                    <Field key={preference.id} orientation="horizontal">
                        <Switch
                            id={preference.id}
                            data-test={preference.id}
                            aria-describedby={`${preference.id}-description`}
                            checked={preference.isChecked}
                            onCheckedChange={() => preference.onToggle()}
                        />
                        <FieldContent>
                            <FieldLabel htmlFor={preference.id}>
                                {preference.label}
                            </FieldLabel>
                            <FieldDescription
                                id={`${preference.id}-description`}
                            >
                                {preference.description}
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                ))}
            </FieldGroup>
        </div>
    );
}
