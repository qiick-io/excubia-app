/**
 * A record id as it crosses the app boundary. APIs expose UUIDs, so strings are
 * the common case; numbers stay valid for apps that have not migrated their
 * internal ids yet. Both are accepted, never converted into one another.
 *
 * Identity is strict: `'7'` and `7` are different records. Compare ids with
 * `===` / `includes()` on values of the same type, never with `Number()` or
 * `parseInt()`, so a UUID is never truncated and a numeric id never changes.
 */
export type RecordId = string | number;
