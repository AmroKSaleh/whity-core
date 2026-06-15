import { type APIRequestContext, type Page, expect, request } from '@playwright/test';
import { ADMIN, type Credentials } from './constants';

/**
 * Lightweight API helpers used for test setup and (best-effort) cleanup.
 *
 * All requests go through the frontend's own `/api/v1/*` proxy on the baseURL,
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
  const response = await context.post('/api/v1/login', {
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
  await api.delete(`/api/v1/roles/${id}`).catch(() => undefined);
}

/** Best-effort user deletion by id. */
export async function deleteUser(api: APIRequestContext, id: number): Promise<void> {
  await api.delete(`/api/v1/users/${id}`).catch(() => undefined);
}

/**
 * Best-effort organizational-unit deletion by id.
 *
 * The `/api/v1/ous/{id}` DELETE endpoint returns HTTP 204 from the backend. The
 * Next.js proxy now forwards null-body statuses (204/205/304) without a body
 * (WC-101), so this resolves with a clean 204 rather than a proxy 500.
 */
export async function deleteOu(api: APIRequestContext, id: number): Promise<void> {
  await api.delete(`/api/v1/ous/${id}`).catch(() => undefined);
}

/** Fetch the current role list (admin-only endpoint). */
export async function listRoles(
  api: APIRequestContext
): Promise<Array<{ id: number; name: string }>> {
  const res = await api.get('/api/v1/roles');
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
  const res = await api.get('/api/v1/ous');
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

/**
 * Ensure a user account exists and return its id. Looks the email up via
 * GET /api/users first; when absent, creates it via POST /api/users. The role
 * is passed by NAME (the backend resolves tenant-visible role names, WC-113).
 *
 * Idempotent across runs against a persistent dev database. NOTE: an existing
 * account is returned as-is — its password is never reset, so a mismatch with
 * the expected credentials surfaces later as a loud UI-login failure.
 */
export async function ensureUser(
  api: APIRequestContext,
  opts: { email: string; password: string; role: string }
): Promise<number> {
  const existing = await findUserIdByEmail(api, opts.email);
  if (existing !== null) {
    return existing;
  }

  const res = await api.post('/api/v1/users', {
    data: { email: opts.email, password: opts.password, role: opts.role },
  });
  if (res.status() === 409) {
    // Lost a (re-run) race: the account appeared between the lookup and the
    // create. Resolve it by email instead of failing.
    const raced = await findUserIdByEmail(api, opts.email);
    if (raced !== null) {
      return raced;
    }
  }
  expect(res.status(), `creating user ${opts.email} should return 201`).toBe(201);
  const body = (await res.json()) as { data: { id: number } };
  return body.data.id;
}

/**
 * Ensure a LIVE delegation of the given permissions to the grantee exists,
 * creating only the missing ones (one POST for the whole missing set; the
 * backend writes one row per permission).
 *
 * The grantor is whoever `api` is authenticated as, and the delegation API
 * enforces the subset invariant server-side: a permission the grantor does not
 * hold is rejected with 422, which this helper surfaces as a hard failure.
 * GET /api/delegations excludes revoked rows by default, so revoked grants are
 * correctly re-created rather than mistaken for live ones.
 */
export async function ensureDelegation(
  api: APIRequestContext,
  opts: {
    granteeType: 'role' | 'user';
    granteeId: number;
    permissions: string[];
  }
): Promise<void> {
  const listRes = await api.get(
    `/api/v1/delegations?granteeType=${opts.granteeType}&granteeId=${opts.granteeId}`
  );
  let live: string[] = [];
  if (listRes.ok()) {
    const body = (await listRes.json()) as {
      data?: Array<{ permission: string; revokedAt: string | null }>;
    };
    live = (body.data ?? [])
      .filter((d) => d.revokedAt === null)
      .map((d) => d.permission);
  }

  const missing = opts.permissions.filter((p) => !live.includes(p));
  if (missing.length === 0) {
    return;
  }

  const createRes = await api.post('/api/v1/delegations', {
    data: {
      granteeType: opts.granteeType,
      granteeId: opts.granteeId,
      permissions: missing,
      ouId: null,
    },
  });
  expect(
    createRes.status(),
    `delegating [${missing.join(', ')}] to ${opts.granteeType} ${opts.granteeId} ` +
      'should return 201 (422 means the grantor does not hold the permission)'
  ).toBe(201);
}

/**
 * Best-effort revocation of every LIVE delegation held by the given grantee.
 * Used by matrix-spec cleanup so a failed test can never leave a live
 * throwaway delegation behind (revoked rows are list-hidden by default and
 * harmless). Never throws.
 */
export async function revokeDelegationsFor(
  api: APIRequestContext,
  granteeType: 'role' | 'user',
  granteeId: number
): Promise<void> {
  try {
    const res = await api.get(
      `/api/v1/delegations?granteeType=${granteeType}&granteeId=${granteeId}`
    );
    if (!res.ok()) return;
    const body = (await res.json()) as {
      data?: Array<{ id: number; revokedAt: string | null }>;
    };
    for (const delegation of body.data ?? []) {
      if (delegation.revokedAt === null) {
        await api.delete(`/api/v1/delegations/${delegation.id}`).catch(() => undefined);
      }
    }
  } catch {
    // Best-effort cleanup.
  }
}

/** Best-effort person deletion by id (requires relations:manage). */
export async function deletePerson(api: APIRequestContext, id: number): Promise<void> {
  await api.delete(`/api/v1/persons/${id}`).catch(() => undefined);
}

/** Find a person id by exact display name, or null if absent. */
export async function findPersonIdByName(
  api: APIRequestContext,
  displayName: string
): Promise<number | null> {
  const res = await api.get('/api/v1/persons');
  if (!res.ok()) return null;
  const body = (await res.json()) as {
    data?: Array<{ id: number; displayName: string }>;
  };
  const match = (body.data ?? []).find((p) => p.displayName === displayName);
  return match ? match.id : null;
}

/**
 * Best-effort deletion of every HelloWorld greeting whose message contains the
 * given marker. Matrix specs tag the greetings they create with a
 * uniqueSuffix-based marker, so cleanup removes exactly what the test created
 * and tolerates pre-existing rows on a shared dev database. Never throws.
 */
export async function deleteGreetingsMatching(
  api: APIRequestContext,
  marker: string
): Promise<void> {
  try {
    const res = await api.get('/api/v1/hello/greetings');
    if (!res.ok()) return;
    const body = (await res.json()) as {
      data?: Array<{ id: number; message: string }>;
    };
    for (const row of body.data ?? []) {
      if (row.message.includes(marker)) {
        await api.delete(`/api/v1/hello/greetings/${row.id}`).catch(() => undefined);
      }
    }
  } catch {
    // Best-effort cleanup.
  }
}

/** Find a user id by exact email, or null if absent. */
export async function findUserIdByEmail(
  api: APIRequestContext,
  email: string
): Promise<number | null> {
  const res = await api.get('/api/v1/users');
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
    .patch(`/api/v1/users/${userId}`, { data: { role: roleName } })
    .catch(() => undefined);
}

/** Read the current account's 2FA status ({ enabled, backup_codes_available }). */
export async function getTwoFactorStatus(
  api: APIRequestContext
): Promise<{ enabled: boolean; backup_codes_available: number }> {
  const res = await api.get('/api/v1/auth/2fa/status');
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
  const setupRes = await api.post('/api/v1/auth/2fa/setup');
  expect(setupRes.status(), '2FA setup should return 200').toBe(200);
  const setup = (await setupRes.json()) as { secret: string };
  const code = await computeCode(setup.secret);

  const confirmRes = await api.post('/api/v1/auth/2fa/confirm', {
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
