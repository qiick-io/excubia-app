import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';

/** One role the current user may assign, as the app names it. */
export type RolePickerRole = {
    /** The role's stored name (`admin`), the value the server validates. */
    name: string;
    /** Human label (`Admin`); without one the name is shown title-cased. */
    label?: string;
    description?: string;
};

export type RolePickerProps = {
    /** The roles the user may assign plus the ones already selected, so every selected role can be seen and cleared. */
    roles: readonly RolePickerRole[];
    /** Selected role names. */
    value: readonly string[];
    onChange: (roleNames: string[]) => void;
    /** The role that stands alone; `super-admin` in every qiick product. */
    superAdminRoleName?: string;
    /** The server's validation message for the roles. */
    error?: string;
    isDisabled?: boolean;
    /** Marks the legend as a required field. */
    isRequired?: boolean;
};

/**
 * `admin` → `Admin`, `super-admin` → `Super admin`: a readable fallback so a
 * raw slug never reaches the screen when the app sends no label.
 * @param {string} roleName - The stored role name.
 * @returns {string}
 */
export function humanizeRoleName(roleName: string): string {
    const words = roleName.replace(/[-_]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The roles a user holds, picked from a checkbox list (decision 2026-11-26).
 * Super-admin is exclusive: picking it clears and disables every other role,
 * because it already holds every permission. Any other roles combine, and
 * their permissions add up, which the helper text says. The server enforces
 * the same rule (laravel-access `ExclusiveSuperAdminRoleRule`).
 * @returns {JSX.Element}
 */
export function RolePicker({
    roles,
    value,
    onChange,
    superAdminRoleName = 'super-admin',
    error,
    isDisabled = false,
    isRequired = false,
}: RolePickerProps) {
    const { t } = useTranslation();
    const pickerId = useId();
    const descriptionId = `${pickerId}-description`;
    const errorId = `${pickerId}-error`;
    const isSuperAdminSelected = value.includes(superAdminRoleName);

    function toggleRole(roleName: string, isChecked: boolean): void {
        if (!isChecked) {
            onChange(value.filter((selected) => selected !== roleName));

            return;
        }

        onChange(
            roleName === superAdminRoleName ? [roleName] : [...value, roleName],
        );
    }

    return (
        <FieldSet
            className="gap-3"
            data-invalid={error ? true : undefined}
            aria-describedby={
                error ? `${descriptionId} ${errorId}` : descriptionId
            }
        >
            <FieldLegend variant="label" className="mb-0">
                {t('rolePicker.legend')}
                {isRequired ? ' *' : null}
            </FieldLegend>
            <FieldDescription id={descriptionId}>
                {t('rolePicker.description')}
            </FieldDescription>
            <div className="flex flex-col gap-3">
                {roles.map((role) => {
                    // Prefixed so a role named `description` or `error` cannot reuse the group's ids.
                    const checkboxId = `${pickerId}-role-${role.name}`;
                    const roleDescriptionId = `${checkboxId}-description`;
                    const isSuperAdminRole = role.name === superAdminRoleName;
                    const isSelected = value.includes(role.name);
                    // A role saved next to super-admin before the rule stays
                    // checked and enabled, so it can be cleared.
                    const isLockedBySuperAdmin =
                        isSuperAdminSelected &&
                        !isSuperAdminRole &&
                        !isSelected;
                    // The exclusivity hint always shows: picking the role clears the others.
                    const description = isSuperAdminRole
                        ? [role.description, t('rolePicker.superAdminHint')]
                              .filter(Boolean)
                              .join(' ')
                        : role.description;

                    return (
                        <Field
                            key={role.name}
                            orientation="horizontal"
                            data-disabled={
                                isDisabled || isLockedBySuperAdmin
                                    ? true
                                    : undefined
                            }
                        >
                            <Checkbox
                                id={checkboxId}
                                checked={isSelected}
                                disabled={isDisabled || isLockedBySuperAdmin}
                                aria-describedby={
                                    description ? roleDescriptionId : undefined
                                }
                                onCheckedChange={(checked) =>
                                    toggleRole(role.name, checked === true)
                                }
                            />
                            <div className="flex flex-col gap-0.5">
                                <FieldLabel htmlFor={checkboxId}>
                                    {role.label ?? humanizeRoleName(role.name)}
                                </FieldLabel>
                                {description ? (
                                    <FieldDescription id={roleDescriptionId}>
                                        {description}
                                    </FieldDescription>
                                ) : null}
                            </div>
                        </Field>
                    );
                })}
            </div>
            <FieldError id={errorId}>{error}</FieldError>
        </FieldSet>
    );
}
