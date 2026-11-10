import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

type AppErrorBoundaryProps = {
    children: ReactNode;
};

type AppErrorBoundaryState = {
    hasError: boolean;
    error: Error | null;
};

/**
 * Fallback screen of the error boundary. A function component so it can read
 * the active locale through `useTranslation` (class components cannot use hooks).
 * @param {{ error: Error; onRetry: () => void }} props - The caught error and the reset callback.
 * @returns {JSX.Element}
 */
function AppErrorFallback({
    error,
    onRetry,
}: {
    error: Error;
    onRetry: () => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-background p-6">
            <div className="max-w-md space-y-2 text-center">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('errorBoundary.title')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('errorBoundary.description')}
                </p>
                {import.meta.env.DEV && (
                    <pre className="mt-4 max-h-40 overflow-auto rounded-lg bg-muted p-3 text-left text-xs">
                        {error.message}
                    </pre>
                )}
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Button type="button" onClick={onRetry}>
                    {t('errorBoundary.retry')}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                        window.location.reload();
                    }}
                >
                    {t('errorBoundary.reload')}
                </Button>
            </div>
        </div>
    );
}

/**
 * Catches JavaScript errors in the child tree during render (class boundary per React docs).
 * @see https://react.dev/reference/react/Component#catching-rendering-errors-with-an-error-boundary
 */
export class AppErrorBoundary extends Component<
    AppErrorBoundaryProps,
    AppErrorBoundaryState
> {
    constructor(props: AppErrorBoundaryProps) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(
        error: Error,
    ): Partial<AppErrorBoundaryState> {
        return { hasError: true, error };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('[AppErrorBoundary]', error, info.componentStack);
    }

    render(): ReactNode {
        if (this.state.hasError && this.state.error) {
            return (
                <AppErrorFallback
                    error={this.state.error}
                    onRetry={() => {
                        this.setState({ hasError: false, error: null });
                    }}
                />
            );
        }

        return this.props.children;
    }
}
