import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { TruncatedText } from '@/components/admin/truncated-text';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';

/**
 * One breadcrumb segment, the starter kit's `BreadcrumbItem` from `@/types`.
 * Declared here because `shadcn add` rewrites `@/types` imports.
 */
export type BreadcrumbItemType = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** A small decorative visual before the title, such as the record's avatar. */
    leading?: ReactNode;
};

/**
 * Renders a linked breadcrumb trail from the given items.
 * @param {{ breadcrumbs: BreadcrumbItemType[] }} props - Component props.
 * @param {BreadcrumbItemType[]} props.breadcrumbs - Ordered trail segments (last item is current page).
 * @returns {JSX.Element}
 */
export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    const { t } = useTranslation();

    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb
                    className="min-w-0"
                    aria-label={t('a11y.breadcrumb')}
                >
                    <BreadcrumbList className="flex-nowrap overflow-hidden">
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;

                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem className="max-w-[9rem] min-w-0 shrink md:max-w-[12rem]">
                                        {isLast ? (
                                            <BreadcrumbPage className="flex max-w-full min-w-0 items-center gap-1.5">
                                                {item.leading}
                                                <TruncatedText
                                                    text={item.title}
                                                />
                                            </BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink
                                                asChild
                                                className="block max-w-full min-w-0"
                                            >
                                                <Link
                                                    href={item.href}
                                                    className="flex max-w-full min-w-0 items-center gap-1.5"
                                                >
                                                    {item.leading}
                                                    <TruncatedText
                                                        text={item.title}
                                                    />
                                                </Link>
                                            </BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && <BreadcrumbSeparator />}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
