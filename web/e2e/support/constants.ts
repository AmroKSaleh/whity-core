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
 * The seeded SYSTEM-TENANT (id 0) admin. It holds the admin role in tenant 0,
 * which grants `settings:manage` (migration 026), so it is the ONLY identity
 * allowed to read/write the GLOBAL platform defaults and global branding after
 * WC-235 restricted those surfaces to the system tenant. Regular tenant admins
 * (e.g. {@link ADMIN}, tenant 1) can only manage their own tenant's overrides.
 *
 * The public effective-branding endpoint (GET /api/v1/branding) resolves the
 * tenant by host and carries NO auth context, so in CI (no per-tenant host) the
 * document <title>, login heading and sidebar all reflect GLOBAL branding —
 * which is why every branding-DISPLAY assertion is driven by this account's
 * global writes, not a tenant override.
 */
export const SYSTEM_ADMIN: Credentials = {
  email: process.env.E2E_SYSTEM_EMAIL ?? 'superuser@example.com',
  password: process.env.E2E_SYSTEM_PASSWORD ?? 'superuser123',
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
 * Sidebar sections an ADMIN sees. `GET /api/navigation` is now RBAC-filtered
 * per caller server-side (WC-175 #191): each link is returned only if the
 * caller satisfies that page's RBAC, so the set differs by role (a plain user
 * sees only "Settings"). This is the ADMIN-visible set — navigation.spec.ts
 * runs authenticated as admin, which holds every gated permission and so sees
 * all of these.
 */
export const SIDEBAR_SECTIONS = [
  { label: 'Dashboard', href: '/admin' },
  { label: 'Users', href: '/admin/users' },
  { label: 'Roles', href: '/admin/roles' },
  { label: 'Organizational Units', href: '/admin/ous' },
  { label: 'Tenants', href: '/admin/tenants' },
  { label: 'Settings', href: '/settings' },
  { label: 'Website Settings', href: '/admin/settings' },
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
