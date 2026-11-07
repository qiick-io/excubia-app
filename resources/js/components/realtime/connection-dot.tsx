import { useTranslation } from 'react-i18next';
import { AvatarBadge } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

/** State of the app's realtime (WebSocket) connection. */
export type ConnectionState =
    'connected' | 'connecting' | 'disabled' | 'offline';

/**
 * Green when connected, amber while connecting, grey when realtime is off, red
 * only when the connection is lost (an error, unlike a user being offline).
 */
const TONES: Record<ConnectionState, string> = {
    connected: 'bg-success',
    connecting: 'bg-warning animate-pulse',
    disabled: 'bg-muted-foreground',
    offline: 'bg-destructive',
};

/**
 * The realtime connection as an `AvatarBadge`: render it inside `<Avatar>`, so
 * it sits bottom-right like every presence dot. The app passes its connection
 * state (in externa-core, `useEchoConnection()` for Reverb).
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
        <AvatarBadge
            className={cn('pointer-events-none', TONES[state], className)}
            title={label}
            aria-label={label}
            role="status"
            data-test="realtime-connection-dot"
            data-state={state}
        />
    );
}
