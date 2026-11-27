const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const TIME_PATTERN = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

/** A Gregorian calendar day carried in UTC for DayPicker and Intl formatting. */
export function dateFromWire(wire: string): Date | undefined {
    const match = DATE_PATTERN.exec(wire);

    if (!match || match[1] === '0000') {
        return undefined;
    }

    const [, year, month, day] = match;
    const date = new Date(0);
    date.setUTCFullYear(Number(year), Number(month) - 1, Number(day));

    return dateToWire(date) === wire ? date : undefined;
}

/** Serialize the UTC carrier's calendar day without including its time. */
function dateToWire(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/** Format a wire value, parse localized text, and provide a localized input example. */
export function datePickerFormat(
    language: string,
    hasTime: boolean,
): {
    format: (wire: string | null) => string;
    parse: (text: string) => string | null;
    example: string;
} {
    const formatter = new Intl.DateTimeFormat(language, {
        calendar: 'gregory',
        numberingSystem: 'latn',
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
    const example = new Date(Date.UTC(2026, 10, 24));
    const parts = formatter.formatToParts(example);

    const formatDate = (date: Date): string =>
        formatter
            .formatToParts(date)
            .map(({ type, value }) =>
                type === 'year' ? value.padStart(4, '0') : value,
            )
            .join('');

    const format = (wire: string | null): string => {
        const [day, time, extra] = wire?.split('T') ?? [];
        const date = day ? dateFromWire(day) : undefined;

        if (
            !date ||
            extra !== undefined ||
            (hasTime ? !TIME_PATTERN.test(time ?? '') : time !== undefined)
        ) {
            return '';
        }

        return hasTime ? `${formatDate(date)} ${time}` : formatDate(date);
    };

    const parse = (text: string): string | null => {
        const [day, clock, extra] = text.trim().split(/\s+/);
        const time = clock?.padStart(5, '0');
        const tokens = day.split(/(\D+)/);

        if (
            extra !== undefined ||
            (hasTime ? !TIME_PATTERN.test(time ?? '') : time !== undefined) ||
            tokens.length !== parts.length ||
            parts.some(
                ({ type, value }, index) =>
                    type === 'literal' && tokens[index] !== value,
            )
        ) {
            return null;
        }

        const fields = new Map(
            parts.map(({ type }, index) => [type, tokens[index]]),
        );
        const month = fields.get('month')?.padStart(2, '0');
        const monthDay = fields.get('day')?.padStart(2, '0');
        const wireDate = `${fields.get('year')}-${month}-${monthDay}`;

        if (!dateFromWire(wireDate)) {
            return null;
        }

        return hasTime ? `${wireDate}T${time}` : wireDate;
    };

    return {
        format,
        parse,
        example: hasTime
            ? `${formatter.format(example)} 14:30`
            : formatter.format(example),
    };
}

/** Reject unknown profile zones and fixed offsets before opening the calendar. */
export function isDatePickerTimeZone(timeZone: string | undefined): boolean {
    try {
        new Intl.DateTimeFormat('en', { timeZone });

        return !timeZone || !/^[+-]/.test(timeZone);
    } catch {
        return false;
    }
}

/** Keep the previous wall-clock time when possible, including on either bound's day. */
export function datePickerDayValue(
    day: Date,
    value: string | null,
    hasTime: boolean,
    min: string | undefined,
    max: string | undefined,
): string {
    const wireDate = dateToWire(day);

    if (!hasTime) {
        return wireDate;
    }

    const time = value?.split('T')[1] ?? '00:00';
    const next = `${wireDate}T${time}`;

    if (min && next < min) {
        return min;
    }

    if (max && next > max) {
        return max;
    }

    return next;
}
