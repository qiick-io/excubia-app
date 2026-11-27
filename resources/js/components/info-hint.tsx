import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * The one place a page or card explains itself: an info icon next to its title that opens a
 * short popover, instead of a paragraph under the title (app-ui docs/page.md, "Page text").
 * A popover rather than a tooltip, so it opens on touch and stays open while it is read.
 */
export function InfoHint({
    label,
    children,
}: {
    /** The accessible name of the icon button, e.g. "About upgrade readiness". */
    label: string;
    children: ReactNode;
}) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={label}
                    className="text-muted-foreground"
                >
                    <Info />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                aria-label={label}
                className="w-72 text-sm text-muted-foreground"
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}
