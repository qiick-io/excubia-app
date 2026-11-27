import { Head, Link, usePage } from '@inertiajs/react';
import { FileQuestion, ServerCrash, ShieldX, Wrench } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard, login } from '@/routes';

type ErrorStatus = 403 | 404 | 500 | 503;

const statusIcons: Record<ErrorStatus, LucideIcon> = {
    403: ShieldX,
    404: FileQuestion,
    500: ServerCrash,
    503: Wrench,
};

/**
 * The qiick error page (app-ui `docs/error-pages.md`) for 403, 404, 500 and 503: inside the app
 * shell when the user is signed in, the auth layout when not, with one way back. A 500 shows no
 * details; 500 and 503 offer "Try again" because the page itself may work on a reload.
 */
export default function ErrorPage({ status }: { status: ErrorStatus }) {
    const { t } = useTranslation();
    const isSignedIn = Boolean(
        usePage<{ auth?: { user?: unknown } }>().props.auth?.user,
    );
    const StatusIcon = statusIcons[status];
    const title = t(`errors.${status}.title`);
    const description = t(`errors.${status}.description`);
    const shouldRetry = status === 500 || status === 503;

    const action = shouldRetry ? (
        <Button type="button" onClick={() => window.location.reload()}>
            {t('errors.tryAgain')}
        </Button>
    ) : (
        <Button asChild>
            <Link href={isSignedIn ? dashboard() : login()}>
                {isSignedIn ? t('errors.backToDashboard') : t('errors.signIn')}
            </Link>
        </Button>
    );

    if (!isSignedIn) {
        return (
            <AuthLayout title={title} description={description}>
                <Head title={title} />
                <div data-test="error-page" className="flex justify-center">
                    {action}
                </div>
            </AuthLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={[{ title, href: dashboard() }]} hasPageHeading>
            <Head title={title} />
            <PageLayout>
                <Empty data-test="error-page">
                    <EmptyHeader>
                        <p className="text-sm font-medium text-muted-foreground">
                            {status}
                        </p>
                        <EmptyMedia variant="icon">
                            <StatusIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            <h1>{title}</h1>
                        </EmptyTitle>
                        <EmptyDescription>{description}</EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>{action}</EmptyContent>
                </Empty>
            </PageLayout>
        </AppLayout>
    );
}
