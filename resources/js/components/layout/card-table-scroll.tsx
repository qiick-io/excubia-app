import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useScrollRegion } from '@/hooks/use-scroll-region';
import { cn } from '@/lib/utils';

/**
 * The scroll box of a table inside a card (app-ui docs/dashboard.md, "Card grids"): the rows
 * scroll under a sticky header row on the card surface, so a long table never stretches its
 * row of cards. Give it a height: `max-h-80` for a fixed one, or `flex-1` inside a card that
 * fills its row (`xl:h-0 xl:min-h-full`, `CardContent` a flex column). The Table's own wrapper stops
 * scrolling, so the header sticks to this box; the bottom rule is an inset shadow because
 * collapsed borders scroll away, and `scroll-pt-10` keeps a focused row out from under the
 * sticky header. While it scrolls it is a named, focusable region; `outline-hidden` keeps an
 * outline in forced-colors mode, where the ring (a box shadow) disappears.
 */
export function CardTableScroll({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const scrollRegionRef = useScrollRegion<HTMLDivElement>(
        t('a11y.scrollableTable'),
    );

    return (
        <div
            ref={scrollRegionRef}
            data-slot="card-table-scroll"
            className={cn(
                'min-h-0 scroll-pt-10 overflow-auto outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset [&_[data-slot=table-container]]:overflow-visible [&_thead]:sticky [&_thead]:top-0 [&_thead]:z-10 [&_thead]:bg-card [&_thead_th]:shadow-[inset_0_-1px_0_var(--color-border)] [&_thead_tr]:border-b-0',
                className,
            )}
        >
            {children}
        </div>
    );
}
