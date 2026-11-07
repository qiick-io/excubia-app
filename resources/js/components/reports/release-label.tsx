/** A 40-character commit SHA, as the CI variables send it. */
const COMMIT_SHA = /^[0-9a-f]{40}$/i;

/**
 * A release identifier for display (#106): a commit SHA is cut to its first 7 characters, as
 * git shows it; tags and version names stay whole.
 */
export function shortRelease(release: string): string {
    return COMMIT_SHA.test(release) ? release.slice(0, 7) : release;
}

/**
 * A release in monospace, shortened by shortRelease(), with the full identifier in the
 * tooltip.
 */
export function ReleaseLabel({ release }: { release: string }) {
    return (
        <span className="font-mono" title={release}>
            {shortRelease(release)}
        </span>
    );
}
