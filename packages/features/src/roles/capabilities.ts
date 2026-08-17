/**
 * Role-management capability slugs — kept in the package (pure TS) so both the
 * web and desktop clients gate the Roles UI on the exact same strings the
 * backend's RbacMiddleware enforces. These are UI hints only; the server stays
 * authoritative.
 */

/** Permission required to create/edit roles. */
export const ROLES_WRITE = 'roles:write';

/** Permission required to delete roles. */
export const ROLES_DELETE = 'roles:delete';
