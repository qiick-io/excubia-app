import { CalendarIcon } from 'lucide-react';
import { useId, useState } from 'react';
import { TZDate } from 'react-day-picker';
import { de, enGB, enUS, it } from 'react-day-picker/locale';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    dateFromWire,
    datePickerDayValue,
    datePickerFormat,
    isDatePickerTimeZone,
} from '@/lib/date-picker';

type DatePickerProps = {
    label: string;
    value: string | null;
    onChange: (value: string | null) => void;
    /** Name of the hidden field that submits the ISO wall-clock value. */
    name?: string;
    /** Includes a local time to the minute. */
    hasTime?: boolean;
    /** IANA zone of the wall time. Defaults to the user's device zone. */
    timeZone?: string;
    /** Inclusive bounds in the same format and zone as value. */
    min?: string;
    max?: string;
    disabled?: boolean;
    required?: boolean;
    /** Keep the accessible label in a compact filter toolbar. */
    isLabelHidden?: boolean;
    /** Server validation error for the field. */
    error?: string;
};

type DatePickerEdit = {
    text: string;
    context: string;
};

const DAY_PICKER_LOCALES: Record<string, typeof enUS> = {
    de,
    en: enUS,
    'en-gb': enGB,
    it,
};

// This initializes DayPicker's roving focus when the user opens the popover.
const DAY_PICKER_FOCUS = { autoFocus: true };

function getCalendarLocale(language: string | undefined) {
    const tag = language?.toLowerCase() ?? 'en';
    const [base] = tag.split('-');

    return DAY_PICKER_LOCALES[tag] ?? DAY_PICKER_LOCALES[base] ?? enUS;
}

/** Edit one date or wall-clock date-time while keeping the form value locale-independent. */
export function DatePicker({
    label,
    value,
    onChange,
    name,
    hasTime = false,
    timeZone,
    min,
    max,
    disabled,
    required,
    isLabelHidden,
    error,
}: DatePickerProps) {
    const { t, i18n } = useTranslation();
    const inputId = useId();
    const [edit, setEdit] = useState<DatePickerEdit | null>(null);
    const [isValidationVisible, setIsValidationVisible] = useState(false);
    const [today, setToday] = useState<Date>();
    const locale = getCalendarLocale(i18n.language);
    const language = locale.code;
    const { example, format, parse } = datePickerFormat(language, hasTime);
    const formatted = format(value);
    const editContext = JSON.stringify([value, language, hasTime, min, max]);

    // Clear an edit if its value, format, or bounds changed externally.
    if (edit && edit.context !== editContext) {
        setEdit(null);
        setIsValidationVisible(false);
    }

    const hasInvalidValue = value !== null && formatted === '';
    const text = edit?.text ?? (hasInvalidValue ? value : formatted);
    const parsed = edit ? parse(edit.text) : formatted ? value : null;
    const minimumDay = dateFromWire(min?.slice(0, 10) ?? '0001-01-01');
    const maximumDay = dateFromWire(max?.slice(0, 10) ?? '9999-12-31');
    const bounds = {
        min: min ? format(min) : '',
        max: max ? format(max) : '',
    };
    const isBeforeMinimum = Boolean(parsed && min && parsed < min);
    const isAfterMaximum = Boolean(parsed && max && parsed > max);
    const hasInvalidBounds =
        (min !== undefined && !bounds.min) ||
        (max !== undefined && !bounds.max) ||
        Boolean(min && max && min > max);
    const hasInvalidTimeZone = !isDatePickerTimeZone(timeZone);
    const canUseCalendar =
        !disabled && !hasInvalidBounds && !hasInvalidTimeZone;
    let validationError = '';

    if (hasInvalidTimeZone) {
        validationError = t('datePicker.timeZoneError');
    } else if (hasInvalidBounds) {
        validationError = t('datePicker.boundsError');
    } else if (text === '' && required) {
        validationError = t('datePicker.required');
    } else if (text !== '' && !parsed) {
        validationError = t('datePicker.formatError', { example });
    } else if (isBeforeMinimum) {
        validationError = t('datePicker.minimumError', bounds);
    } else if (isAfterMaximum) {
        validationError = t('datePicker.maximumError', bounds);
    }

    const shouldShowValidation =
        isValidationVisible ||
        hasInvalidTimeZone ||
        hasInvalidBounds ||
        (hasInvalidValue && !edit);
    const visibleError = error || (shouldShowValidation ? validationError : '');
    const selectedDay =
        parsed && !isBeforeMinimum && !isAfterMaximum
            ? dateFromWire(parsed.slice(0, 10))
            : undefined;
    let openingDay = selectedDay ?? today;

    if (openingDay && minimumDay && openingDay < minimumDay) {
        openingDay = minimumDay;
    } else if (openingDay && maximumDay && openingDay > maximumDay) {
        openingDay = maximumDay;
    }

    const dayFormatter = new Intl.DateTimeFormat(language, {
        dateStyle: 'full',
        timeZone: 'UTC',
    });
    const pickerLabel = t('datePicker.chooseDate', { label });
    const zoneLabel = timeZone ?? t('datePicker.deviceTimeZone');
    const hintId = `${inputId}-hint`;
    const errorId = `${inputId}-error`;

    const handleOpenChange = (isOpen: boolean): void => {
        if (!isOpen) {
            setToday(undefined);

            return;
        }

        // Calendar math runs in UTC. Only "today" depends on the user's zone.
        const zonedNow = TZDate.tz(
            timeZone ?? new Intl.DateTimeFormat().resolvedOptions().timeZone,
        );
        setToday(
            new Date(
                Date.UTC(
                    zonedNow.getFullYear(),
                    zonedNow.getMonth(),
                    zonedNow.getDate(),
                ),
            ),
        );
    };

    const commit = (next: string | null): void => {
        setEdit(null);
        setIsValidationVisible(false);

        if (next !== value) {
            onChange(next);
        }
    };

    const handleTextChange = (nextText: string): void => {
        if (!canUseCalendar) {
            return;
        }

        const next = parse(nextText);

        if (nextText.trim() === '') {
            commit(null);
        } else if (next && (!min || next >= min) && (!max || next <= max)) {
            commit(next);
        } else {
            setEdit({ text: nextText, context: editContext });
        }
    };

    return (
        <Field
            data-invalid={visibleError ? true : undefined}
            data-disabled={disabled}
        >
            <FieldLabel
                htmlFor={inputId}
                className={isLabelHidden ? 'sr-only' : undefined}
            >
                {label}
                {required && <span aria-hidden="true">{' *'}</span>}
            </FieldLabel>
            <Popover
                open={today !== undefined && canUseCalendar}
                onOpenChange={handleOpenChange}
            >
                <div className="flex min-w-0 items-center gap-2">
                    <Input
                        id={inputId}
                        type="text"
                        autoComplete="off"
                        disabled={disabled}
                        required={required}
                        placeholder={example}
                        value={text}
                        aria-invalid={visibleError ? true : undefined}
                        aria-describedby={
                            visibleError ? `${hintId} ${errorId}` : hintId
                        }
                        ref={(input) => {
                            input?.setCustomValidity(validationError);
                        }}
                        onChange={(event) =>
                            handleTextChange(event.target.value)
                        }
                        onBlur={() =>
                            setIsValidationVisible(Boolean(validationError))
                        }
                        onInvalid={() => setIsValidationVisible(true)}
                        className="min-w-0 flex-1 forced-colors:focus-visible:outline"
                    />
                    <PopoverTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            disabled={!canUseCalendar}
                            aria-label={pickerLabel}
                            className="shrink-0 aria-expanded:border-ring forced-colors:focus-visible:outline"
                        >
                            <CalendarIcon aria-hidden="true" />
                        </Button>
                    </PopoverTrigger>
                </div>
                <PopoverContent
                    aria-label={pickerLabel}
                    align="end"
                    className="w-auto p-0"
                >
                    <Calendar
                        {...DAY_PICKER_FOCUS}
                        mode="single"
                        required
                        timeZone="UTC"
                        locale={locale}
                        today={today}
                        selected={selectedDay}
                        defaultMonth={openingDay}
                        startMonth={minimumDay}
                        endMonth={maximumDay}
                        disabled={[
                            ...(minimumDay ? [{ before: minimumDay }] : []),
                            ...(maximumDay ? [{ after: maximumDay }] : []),
                        ]}
                        className="forced-colors:[&_button:focus-visible]:outline"
                        labels={{
                            labelNext: () => t('datePicker.nextMonth'),
                            labelPrevious: () => t('datePicker.previousMonth'),
                            labelDayButton: (day, modifiers) => {
                                const date = dayFormatter.format(day);

                                return modifiers.today
                                    ? t('datePicker.today', { date })
                                    : date;
                            },
                        }}
                        onSelect={(day) => {
                            commit(
                                datePickerDayValue(
                                    day,
                                    hasInvalidValue ? null : value,
                                    hasTime,
                                    min,
                                    max,
                                ),
                            );
                            setToday(undefined);
                        }}
                    />
                    <div className="flex justify-end gap-2 border-t p-2">
                        {text !== '' && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    commit(null);
                                    setToday(undefined);
                                }}
                            >
                                {t('datePicker.clear')}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setToday(undefined)}
                        >
                            {t('datePicker.close')}
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
            <FieldDescription
                id={hintId}
                className={isLabelHidden && !hasTime ? 'sr-only' : undefined}
            >
                {hasTime
                    ? t('datePicker.zonedFormat', {
                          example,
                          timeZone: zoneLabel,
                      })
                    : t('datePicker.format', { example })}
            </FieldDescription>
            <FieldError id={errorId}>{visibleError}</FieldError>
            <span className="sr-only" role="status">
                {formatted
                    ? t('datePicker.selected', { label, value: formatted })
                    : t('datePicker.empty', { label })}
            </span>
            {name && (
                <input
                    type="hidden"
                    name={name}
                    // Preserve stored data if a noValidate form bypasses the input's validation.
                    value={value ?? ''}
                    disabled={disabled}
                />
            )}
        </Field>
    );
}
