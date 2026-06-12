/**
 * Shared constants for the E2E suite.
 *
 * Credentials are the seeded demo accounts documented for the live stack.
 * They can be overridden via environment variables for other environments.
 */

export interface Credentials {
  readonly email: string;
  readonly password: string;
}

export const ADMIN: Credentials = {
  email: process.env.E2E_ADMIN_EMAIL ?? 'admin@example.com',
  password: process.env.E2E_ADMIN_PASSWORD ?? 'admin123',
};

export const REGULAR_USER: Credentials = {
  email: process.env.E2E_USER_EMAIL ?? 'user@example.com',
  password: process.env.E2E_USER_PASSWORD ?? 'user123',
};

/**
 * The delegation-granted account. Unlike ADMIN/REGULAR_USER it is NOT seeded:
 * the auth setup project provisions it idempotently through the admin API
 * (role `user`, so its ROLE grants nothing beyond the regular user) and then
 * delegates {@link DELEGATED_PERMISSIONS} to it. Every permission this account
 * holds beyond the plain user therefore comes from a delegation, which is
 * exactly what the matrix specs exercise.
 */
export const DELEGATE_USER: Credentials = {
  email: process.env.E2E_DELEGATE_EMAIL ?? 'delegate@example.com',
  password: process.env.E2E_DELEGATE_PASSWORD ?? 'delegate123',
};

/**
 * The permissions delegated to DELEGATE_USER by the auth setup project.
 *
 * All three are held by the seeded admin grantor on a fresh database (the
 * delegation API enforces a subset-of-own-permissions invariant, so only
 * admin-held permissions are grantable): `relations:read` and `audit:read`
 * are granted to the admin role by core migrations 020/016, and `hello:view`
 * by the HelloWorld plugin migration (the plugin ships in the repo, so the
 * grant exists in CI too).
 *
 * Verified live effect for the delegate session:
 *  - GET /api/frontend/features  -> 200, ONLY `hello-greetings` (delegations
 *    are honored by RoleChecker; `announcements` stays hidden)
 *  - GET /api/relations          -> 200 (vs 403 for the plain user)
 *  - GET /api/audit-logs         -> 200 (vs 403 for the plain user)
 *  - GET /api/delegations        -> 403 (delegation:manage is NOT delegated)
 *  - GET /api/users              -> 403 (unchanged)
 */
export const DELEGATED_PERMISSIONS = [
  'relations:read',
  'audit:read',
  'hello:view',
] as const;

/**
 * Sidebar sections that exist for an authenticated user. The backend currently
 * returns the same navigation set for every role (access is enforced at the
 * data layer, not by hiding nav items), so both admin and regular users see
 * these links.
 */
export const SIDEBAR_SECTIONS = [
  { label: 'Dashboard', href: '/admin' },
  { label: 'Users', href: '/admin/users' },
  { label: 'Roles', href: '/admin/roles' },
  { label: 'Organizational Units', href: '/admin/ous' },
  { label: 'Tenants', href: '/admin/tenants' },
  { label: 'Settings', href: '/settings' },
] as const;

/**
 * The seeded base roles. Both are GLOBAL (NULL-tenant) roles in the demo seed:
 * they are visible to every tenant via the role LIST endpoint, but a tenant
 * cannot DELETE them (the backend returns 404 "Role not found" for a global
 * role from a non-system tenant — WC-110). The Users edit dropdown resolves
 * these names server-side, unlike its third "Moderator" option which has no
 * backing role (see the documented quirk in users.spec.ts).
 */
export const SEEDED_ROLE_NAMES = ['admin', 'user'] as const;

/**
 * The seeded tenant the demo accounts belong to. Tenant 0 is the SYSTEM tenant
 * and tenant 1 is the "Default Tenant"; neither may be destructively mutated.
 */
export const DEFAULT_TENANT_NAME = 'Default Tenant';

/**
 * Generate a unique, clearly-attributable suffix for test entities so runs
 * never collide on the shared dev database and are trivially identifiable.
 */
export function uniqueSuffix(): string {
  return `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
}
