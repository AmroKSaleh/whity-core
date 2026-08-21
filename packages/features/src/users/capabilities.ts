/**
 * User-administration capability slugs — kept in the package (pure TS) so every
 * client gates the Users UI on the exact same strings `RbacMiddleware` enforces
 * server-side. UI hints only; the server stays authoritative.
 *
 * These are the EXISTING grants (`users:read`/`users:write`, migration 022;
 * `audit:read`, the audit-log routes' own gate). Nothing here is new: a new
 * permission would need a grant migration, and a grant migration reaches only
 * `admin` — silently stripping the capability from operators who run on custom
 * administrative roles (#834).
 */

/** Permission required to create/edit users. */
export const USERS_WRITE = 'users:write';

/** Permission required to delete users. */
export const USERS_DELETE = 'users:delete';

/**
 * Permission required to read the audit trail.
 *
 * Separate from user administration on purpose, and a caller may hold one
 * without the other — which is why the history panel is ABSENT rather than
 * broken when it is missing.
 */
export const AUDIT_READ = 'audit:read';
