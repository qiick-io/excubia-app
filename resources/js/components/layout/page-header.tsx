import type { ReactNode } from 'react';

export type PageHeaderProps = {
    description?: ReactNode;
    subheader?: ReactNode;
    filters?: ReactNode;
    filtersRight?: ReactNode;
};

/**
 * Page description, subheader, and filter row used inside {@link PageLayout}.
 * @param {PageHeaderProps} props - Header section props.
 * @returns {JSX.Element}
 */
export function PageHeader({
    description,
    subheader,
    filters,
    filtersRight,
}: PageHeaderProps) {
    const showFilterRow = Boolean(filters || filtersRight);

    return (
        <div className="flex shrink-0 flex-col gap-3">
            {description ? (
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {description}
                </p>
            ) : null}
            {subheader}
            {showFilterRow ? (
                <div className="flex min-w-0 flex-nowrap items-center gap-3 overflow-x-auto">
                    {filters ? (
                        <div className="flex min-w-0 flex-1 flex-nowrap items-center gap-2">
                            {filters}
                        </div>
                    ) : null}
                    {filtersRight ? (
                        <div className="flex shrink-0 flex-nowrap items-center gap-2">
                            {filtersRight}
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
