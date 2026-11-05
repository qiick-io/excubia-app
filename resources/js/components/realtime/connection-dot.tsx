import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/** State of the app's realtime (WebSocket) connection. */
export type ConnectionState =
    'connected' | 'connecting' | 'disabled' | 'offline';

const TONES: Record<ConnectionState, string> = {
    connected: 'bg-success',
    connecting: 'bg-warning animate-pulse',
    disabled: 'bg-muted-foreground/40',
    offline: 'bg-destructive',
};

/**
 * Presence-style dot for the realtime connection, on the avatar's top-right
 * (the parent must be `relative`). The app passes its connection state (in
 * externa-core, `useEchoConnection()` for Reverb).
 */
export function ConnectionDot({
    state,
    className,
}: {
    state: ConnectionState;
    className?: string;
}) {
    const { t } = useTranslation();
    const label = t(`realtime.${state}`);

    return (
        <span
            className={cn(
                'pointer-events-none absolute -top-0.5 -right-0.5 size-2.5 rounded-full ring-2 ring-sidebar',
                TONES[state],
                className,
            )}
            title={label}
            aria-label={label}
            role="status"
            data-test="realtime-connection-dot"
            data-state={state}
        />
    );
}
