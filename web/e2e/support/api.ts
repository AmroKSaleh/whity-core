import { type APIRequestContext, type Page, expect, request } from '@playwright/test';
import { ADMIN, type Credentials } from './constants';

/**
 * Lightweight API helpers used for test setup and (best-effort) cleanup.
 *
 * All requests go through the frontend's own `/api/*` proxy on the baseURL,
 * which forwards to the backend at http://localhost:8000 and handles the
 * httpOnly auth cookie transparently. This mirrors exactly how the app talks
 * to the backend, so the helpers exercise the same path the UI uses.
 */

/**
 * Build an APIRequestContext that is authenticated as the given account by
 * logging in through the proxy and reusing the resulting httpOnly cookies.
 */
export async function createAuthedApi(
  baseURL: string,
  creds: Credentials = ADMIN
): Promise<APIRequestContext> {
  const context = await request.newContext({
    baseURL,
    // CSRF defense (WC-160): the backend rejects cookie-authenticated
    // state-changing requests (and the auth POSTs) without this header.
    extraHTTPHeaders: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const response = await context.post('/api/login', {
    data: { email: creds.email, password: creds.password },
  });
  if (response.status() === 202) {
    throw new Error(
      `API login for ${creds.email} returned 202 (two-factor required). ` +
        'This account must have 2FA DISABLED for the E2E suite to run ' +
        '(documented prerequisite). On the shared dev stack you can clear it ' +
        "with: docker exec whity_postgres psql -U whity -d whity_core -c " +
        `"UPDATE users SET two_factor_enabled=false WHERE email='${creds.email}';"`
    );
  }
  expect(
    response.status(),
    `API login for ${creds.email} should return 200`
  ).toBe(200);
  return context;
}

/**
 * Delete a role by id. Best-effort: never throws so it is safe in cleanup
 * hooks even if the role was already removed by the test body.
 */
export async function deleteRole(api: APIRequestContext, id: number): Promise<void> {
  await api.delete(`/api/roles/${id}`).catch(() => undefined);
}

/** Best-effort user deletion by id. */
export async function deleteUser(api: APIRequestContext, id: number): Promise<void> {
  await api.delete(`/api/users/${id}`).catch(() => undefined);
}

/**
 * Best-effort organizational-unit deletion by id.
 *
 * The `/api/ous/{id}` DELETE endpoint returns HTTP 204 from the backend. The
 * Next.js proxy now forwards null-body statuses (204/205/304) without a body
 * (WC-101), so this resolves with a clean 204 rather than a proxy 500.
 */
export async function deleteOu(api: APIRequestContext, id: number): Promise<void> {
  await api.delete(`/api/ous/${id}`).catch(() => undefined);
}

/** Fetch the current role list (admin-only endpoint). */
export async function listRoles(
  api: APIRequestContext
): Promise<Array<{ id: number; name: string }>> {
  const res = await api.get('/api/roles');
  if (!res.ok()) return [];
  const body = (await res.json()) as { data?: Array<{ id: number; name: string }> };
  return body.data ?? [];
}

/** Find a role id by exact name, or null if absent. */
export async function findRoleIdByName(
  api: APIRequestContext,
  name: string
): Promise<number | null> {
  const roles = await listRoles(api);
  const match = roles.find((r) => r.name === name);
  return match ? match.id : null;
}

/** Fetch the current OU list (admin-only endpoint). */
export async function listOus(
  api: APIRequestContext
): Promise<Array<{ id: number; name: string }>> {
  const res = await api.get('/api/ous');
  if (!res.ok()) return [];
  const body = (await res.json()) as { data?: Array<{ id: number; name: string }> };
  return body.data ?? [];
}

/** Find an OU id by exact name, or null if absent. */
export async function findOuIdByName(
  api: APIRequestContext,
  name: string
): Promise<number | null> {
  const ous = await listOus(api);
  const match = ous.find((o) => o.name === name);
  return match ? match.id : null;
}

/** Find a user id by exact email, or null if absent. */
export async function findUserIdByEmail(
  api: APIRequestContext,
  email: string
): Promise<number | null> {
  const res = await api.get('/api/users');
  if (!res.ok()) return null;
  const body = (await res.json()) as { data?: Array<{ id: number; email: string }> };
  const match = (body.data ?? []).find((u) => u.email === email);
  return match ? match.id : null;
}

/**
 * Assign a user to a role by NAME through PATCH /api/users/{id}. The backend
 * resolves the role name to a tenant-visible role id (WC-113). Used to set up
 * the "role has active user assignments" 409 scenario without going through the
 * UI. Best-effort.
 */
export async function assignUserRole(
  api: APIRequestContext,
  userId: number,
  roleName: string
): Promise<void> {
  await api
    .patch(`/api/users/${userId}`, { data: { role: roleName } })
    .catch(() => undefined);
}

/** Read the current account's 2FA status ({ enabled, backup_codes_available }). */
export async function getTwoFactorStatus(
  api: APIRequestContext
): Promise<{ enabled: boolean; backup_codes_available: number }> {
  const res = await api.get('/api/auth/2fa/status');
  if (!res.ok()) return { enabled: false, backup_codes_available: 0 };
  return (await res.json()) as { enabled: boolean; backup_codes_available: number };
}

/**
 * Enable 2FA for the CURRENT account through the real backend setup+confirm
 * flow and return the base32 secret + the freshly issued backup codes.
 *
 * Used to deterministically arrange the "2FA is enabled" precondition for the
 * login-challenge specs WITHOUT depending on a prior UI test's shared state
 * (which is fragile under retries). The caller supplies the just-computed TOTP
 * code for the secret (computed via support/totp.ts against the same OTPHP the
 * server uses). Idempotent-ish: if 2FA is already enabled, setup returns 400 and
 * this throws, so callers should reset first.
 */
export async function enableTwoFactor(
  api: APIRequestContext,
  computeCode: (secret: string) => Promise<string>
): Promise<{ secret: string; backupCodes: string[] }> {
  const setupRes = await api.post('/api/auth/2fa/setup');
  expect(setupRes.status(), '2FA setup should return 200').toBe(200);
  const setup = (await setupRes.json()) as { secret: string };
  const code = await computeCode(setup.secret);

  const confirmRes = await api.post('/api/auth/2fa/confirm', {
    data: { code, secret: setup.secret },
  });
  expect(confirmRes.status(), '2FA confirm should return 200').toBe(200);
  const confirm = (await confirmRes.json()) as { backup_codes: string[] };
  return { secret: setup.secret, backupCodes: confirm.backup_codes };
}

/**
 * Read the auth cookies from a browser page's context so an APIRequestContext
 * can be derived from an already-logged-in UI session when convenient.
 */
export async function apiFromPage(page: Page): Promise<APIRequestContext> {
  // The page already holds the httpOnly auth cookie; a fresh authed context
  // is simpler and avoids coupling cleanup to UI cookie internals.
  const baseURL = new URL(page.url()).origin;
  return createAuthedApi(baseURL, ADMIN);
}
