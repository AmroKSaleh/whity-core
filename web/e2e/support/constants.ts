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
 * Generate a unique, clearly-attributable suffix for test entities so runs
 * never collide on the shared dev database and are trivially identifiable.
 */
export function uniqueSuffix(): string {
  return `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
}
