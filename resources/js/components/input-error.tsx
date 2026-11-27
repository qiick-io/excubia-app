import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Inline validation message for form fields; renders nothing when empty.
 * @param {HTMLAttributes<HTMLParagraphElement> & { message?: string | null }} props - Paragraph props plus message.
 * @param {string | null} [props.message] - Error text to display.
 * @returns {JSX.Element | null}
 */
export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string | null }) {
    return message ? (
        <p
            {...props}
            role="alert"
            className={cn('text-sm text-destructive', className)}
        >
            {message}
        </p>
    ) : null;
}
