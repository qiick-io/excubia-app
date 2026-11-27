/**
 * Notification and chat sounds, synthesised with the Web Audio API (no audio
 * files to ship). Both are **off by default**: the app turns them on from the
 * user's preference with `setNotificationSoundPreferences()`.
 *
 * A sound plays only when its preference is on, the tab is visible, and the
 * browser has unlocked audio after a user gesture (autoplay policy).
 */

/** The user's sound preferences; both default to off. */
export type NotificationSoundPreferences = {
    isChatSoundEnabled: boolean;
    isNotificationSoundEnabled: boolean;
};

const DEFAULT_PREFERENCES: NotificationSoundPreferences = {
    isChatSoundEnabled: false,
    isNotificationSoundEnabled: false,
};

let preferences: NotificationSoundPreferences = { ...DEFAULT_PREFERENCES };
let audioContext: AudioContext | null = null;
let isAudioUnlocked = false;
let hasUnlockListeners = false;

function browserWindow(): (Window & typeof globalThis) | null {
    return typeof window === 'undefined' ? null : window;
}

/**
 * Replaces the sound preferences (call it with the user's saved settings).
 */
export function setNotificationSoundPreferences(
    nextPreferences: NotificationSoundPreferences,
): void {
    preferences = { ...nextPreferences };
}

/** A copy of the current sound preferences. */
export function getNotificationSoundPreferences(): NotificationSoundPreferences {
    return { ...preferences };
}

function isVisibleTab(): boolean {
    return browserWindow()?.document.visibilityState === 'visible';
}

function ensureAudioContext(): AudioContext | null {
    const currentWindow = browserWindow();

    if (!currentWindow) {
        return null;
    }

    const AudioContextConstructor =
        currentWindow.AudioContext ??
        (
            currentWindow as typeof currentWindow & {
                webkitAudioContext?: typeof AudioContext;
            }
        ).webkitAudioContext;

    if (!AudioContextConstructor) {
        return null;
    }

    if (!audioContext) {
        audioContext = new AudioContextConstructor();
    }

    return audioContext;
}

/**
 * Resumes the audio context after a user gesture (browser autoplay policy).
 * Safe to call repeatedly: it also resumes a context the browser suspended.
 */
export function unlockNotificationSound(): void {
    const context = ensureAudioContext();

    if (!context) {
        return;
    }

    if (context.state === 'running') {
        isAudioUnlocked = true;

        return;
    }

    if (context.state === 'suspended') {
        void context.resume().then(() => {
            if (context.state === 'running') {
                isAudioUnlocked = true;
            }
        });
    }
}

/**
 * Unlocks audio on the next user gesture anywhere in the document.
 *
 * @returns A cleanup that removes the listeners
 */
export function attachNotificationSoundUnlockListeners(): () => void {
    const currentWindow = browserWindow();

    if (!currentWindow || hasUnlockListeners) {
        return () => undefined;
    }

    hasUnlockListeners = true;

    const handleGesture = (): void => {
        unlockNotificationSound();
    };
    const gestureEvents = ['pointerdown', 'keydown', 'touchstart', 'click'];

    for (const eventName of gestureEvents) {
        currentWindow.document.addEventListener(eventName, handleGesture, {
            passive: true,
        });
    }

    return () => {
        for (const eventName of gestureEvents) {
            currentWindow.document.removeEventListener(
                eventName,
                handleGesture,
            );
        }

        hasUnlockListeners = false;
    };
}

/** Back to the defaults (sounds off, audio locked); for tests. */
export function resetNotificationSoundForTests(): void {
    preferences = { ...DEFAULT_PREFERENCES };
    isAudioUnlocked = false;
    hasUnlockListeners = false;

    if (audioContext) {
        void audioContext.close();
        audioContext = null;
    }
}

/** Simulates a successful unlock without Web Audio; for tests. */
export function markNotificationSoundUnlockedForTests(): void {
    isAudioUnlocked = true;
}

export function shouldPlayChatSound(): boolean {
    return preferences.isChatSoundEnabled && isVisibleTab() && isAudioUnlocked;
}

export function shouldPlayNotificationSound(): boolean {
    return (
        preferences.isNotificationSoundEnabled &&
        isVisibleTab() &&
        isAudioUnlocked
    );
}

/**
 * Chat: play when the unread total rises. The server does not count messages
 * for people already viewing the chat, so a rise means someone else wrote.
 */
export function shouldPlayChatSoundOnUnreadIncrease(
    previousUnreadCount: number,
    nextUnreadCount: number,
): boolean {
    return nextUnreadCount > previousUnreadCount && shouldPlayChatSound();
}

/**
 * Chat over realtime: a `playSound` flag on the event covers viewers (whose
 * unread count does not rise); the unread rise covers everyone else.
 */
export function shouldPlayChatSoundForUnreadEvent(
    previousUnreadCount: number,
    nextUnreadCount: number,
    shouldPlaySoundFlag?: boolean,
): boolean {
    return (
        (shouldPlaySoundFlag === true && shouldPlayChatSound()) ||
        shouldPlayChatSoundOnUnreadIncrease(
            previousUnreadCount,
            nextUnreadCount,
        )
    );
}

type SoundSequence = (context: AudioContext, startTime: number) => void;

/** A soft marimba-like pop: a short pitch dip with a filtered decay. */
function playPop(
    context: AudioContext,
    frequency: number,
    startTime: number,
    durationInSeconds: number,
    volume: number,
    options: { hasBody?: boolean } = {},
): void {
    const { hasBody = false } = options;
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const filter = context.createBiquadFilter();
    const tailInSeconds = durationInSeconds + 0.04;

    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(hasBody ? 2800 : 2200, startTime);
    filter.frequency.exponentialRampToValueAtTime(
        700,
        startTime + durationInSeconds,
    );
    filter.Q.setValueAtTime(0.8, startTime);

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(frequency * 1.05, startTime);
    oscillator.frequency.exponentialRampToValueAtTime(
        frequency,
        startTime + 0.022,
    );

    gain.gain.setValueAtTime(0, startTime);
    gain.gain.linearRampToValueAtTime(volume, startTime + 0.005);
    gain.gain.exponentialRampToValueAtTime(volume * 0.35, startTime + 0.028);
    gain.gain.exponentialRampToValueAtTime(
        0.001,
        startTime + durationInSeconds,
    );

    oscillator.connect(gain);
    gain.connect(filter);

    if (hasBody) {
        const harmonic = context.createOscillator();
        const harmonicGain = context.createGain();

        harmonic.type = 'triangle';
        harmonic.frequency.setValueAtTime(frequency * 2, startTime);
        harmonicGain.gain.setValueAtTime(0, startTime);
        harmonicGain.gain.linearRampToValueAtTime(
            volume * 0.18,
            startTime + 0.004,
        );
        harmonicGain.gain.exponentialRampToValueAtTime(
            0.001,
            startTime + durationInSeconds * 0.75,
        );

        harmonic.connect(harmonicGain);
        harmonicGain.connect(filter);

        harmonic.start(startTime);
        harmonic.stop(startTime + tailInSeconds);
    }

    filter.connect(context.destination);

    oscillator.start(startTime);
    oscillator.stop(startTime + tailInSeconds);
}

/** Chat: one light ding, shorter and softer than the notification chime. */
const chatSoundSequence: SoundSequence = (context, startTime) => {
    playPop(context, 987.77, startTime, 0.16, 0.09);
};

/** Notification: an ascending two-note chime (D5 then G5), about 280 ms. */
const notificationSoundSequence: SoundSequence = (context, startTime) => {
    playPop(context, 587.33, startTime, 0.13, 0.11, { hasBody: true });
    playPop(context, 783.99, startTime + 0.11, 0.15, 0.1, { hasBody: true });
};

/** Schedules a sequence, resuming the context if the browser suspended it. */
function playSoundSequence(sequence: SoundSequence): void {
    const context = ensureAudioContext();

    if (!context) {
        return;
    }

    const start = (): void => {
        if (context.state !== 'running') {
            return;
        }

        isAudioUnlocked = true;
        sequence(context, context.currentTime);
    };

    if (context.state === 'suspended') {
        void context.resume().then(start);

        return;
    }

    start();
}

export function playChatSound(): void {
    if (!shouldPlayChatSound()) {
        return;
    }

    playSoundSequence(chatSoundSequence);
}

export function playNotificationSound(): void {
    if (!shouldPlayNotificationSound()) {
        return;
    }

    playSoundSequence(notificationSoundSequence);
}

/**
 * Unlocks and plays from a click handler, ignoring the preferences and the tab
 * visibility: the "Test sound" button next to the preference.
 */
export function playTestSound(): void {
    unlockNotificationSound();
    playSoundSequence(notificationSoundSequence);
}
