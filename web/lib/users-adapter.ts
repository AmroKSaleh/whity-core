/**
 * web/'s `UsersAdapter` implementation (#882) — wires the data-source-agnostic
 * `UserRecordScreen` (@amroksaleh/features/users) to the REST API via web's own
 * cookie-authenticated `apiClient` (silent token refresh on 401, like every
 * other authenticated fetch in this app).
 *
 * Direct sibling of `web/lib/roles-adapter.ts`, and deliberately the same shape:
 * `createUsersAdapter` holds everything both a web and a desktop client would
 * share — the `{data}` unwrap, the pagination walk, the wire's snake_case, and
 * the 404 → `'not-found'` / 403 → `'forbidden'` sentinels — over the
 * transport-agnostic `{status, body}` seam. A desktop client passes its own
 * transport and gets the same adapter.
 */

import { apiClient } from '@/lib/api-client';
import type {
  OuOption,
  OuOptionsResult,
  UserActivityEntry,
  UserActivityResult,
  UserMembership,
  UserRecord,
  UserUpdateInput,
  UsersAdapter,
} from '@amroksaleh/features/users';
import type { Transport } from '@amroksaleh/features/roles';

/** The backend's page-size ceiling. */
const MAX_PER_PAGE = 100;

/**
 * How many pages the OU walk will take before giving up.
 *
 * A bound, not a belief: an endpoint that kept reporting `hasMore` would
 * otherwise spin forever inside a render. 50 × 100 is 5,000 units, far past any
 * real deployment, and stopping short reports `complete: false` — which
 * WITHHOLDS the picker rather than offering a short list (see below).
 */
const MAX_OU_PAGES = 50;

function isOk(status: number): boolean {
  return status >= 200 && status < 300;
}

/** Narrow `{ data: T }`; returns undefined for any other shape. */
function unwrapData<T>(body: unknown): T | undefined {
  if (typeof body === 'object' && body !== null && 'data' in body) {
    return (body as { data: T }).data;
  }
  return undefined;
}

/** A server-supplied error message, when the body carries one. */
function serverMessage(body: unknown): string {
  if (typeof body === 'object' && body !== null) {
    for (const key of ['message', 'error'] as const) {
      if (key in body) {
        const value = (body as Record<string, unknown>)[key];
        if (typeof value === 'string' && value !== '') return value;
      }
    }
  }
  return '';
}

/** The `pagination.total` an envelope carries, or null when it carries none. */
function paginationTotal(body: unknown): number | null {
  if (typeof body !== 'object' || body === null || !('pagination' in body)) return null;
  const pagination = (body as { pagination: unknown }).pagination;
  if (typeof pagination !== 'object' || pagination === null || !('total' in pagination)) return null;
  const total = (pagination as { total: unknown }).total;
  return typeof total === 'number' ? total : null;
}

/** The wire's user row: camelCase for most fields, `ou_id` for one. */
interface WireUser {
  id: number;
  name: string;
  email: string;
  role: string;
  tenantId: number;
  ou_id?: number | null;
  createdAt?: string | null;
  status?: string;
  accountStatus?: string;
}

/**
 * Normalise the one snake_case field the users contract carries.
 *
 * `ou_id` is published as `ou_id` and every other field as camelCase, which is a
 * wire fact rather than a preference — the published `User` schema says so and
 * other consumers bind it. Translating it HERE keeps that inconsistency out of
 * the component, which is the same job `withCreatedAt` does in the roles
 * adapter.
 */
function toUserRecord(row: WireUser): UserRecord {
  return {
    id: row.id,
    name: row.name ?? '',
    email: row.email ?? '',
    role: row.role ?? '',
    tenantId: row.tenantId ?? 0,
    ouId: row.ou_id ?? null,
    createdAt: row.createdAt ?? null,
    status: row.status ?? '',
    accountStatus: row.accountStatus ?? 'active',
  };
}

interface WireMembership {
  id: number;
  tenantId: number;
  tenantName: string;
  roleId: number;
  role: string;
  ou_id?: number | null;
  isPrimary: boolean;
  status: string;
}

function toMembership(row: WireMembership): UserMembership {
  return {
    id: row.id,
    tenantId: row.tenantId,
    tenantName: row.tenantName ?? '',
    roleId: row.roleId,
    role: row.role ?? '',
    ouId: row.ou_id ?? null,
    isPrimary: row.isPrimary === true,
    status: row.status ?? '',
  };
}

/**
 * The shared adapter factory — a thin function over a {@link Transport}.
 */
export function createUsersAdapter(transport: Transport): UsersAdapter {
  return {
    async getUser(id: number): Promise<UserRecord> {
      const { status, body } = await transport.request('GET', `/api/v1/users/${id}`);
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch user ${id} (${status})`);
      }
      const row = unwrapData<WireUser>(body);
      if (!row) throw new Error(`Malformed user response for ${id}`);
      return toUserRecord(row);
    },

    async listUserMemberships(id: number): Promise<UserMembership[]> {
      const { status, body } = await transport.request('GET', `/api/v1/users/${id}/memberships`);
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch memberships (${status})`);
      }
      return (unwrapData<WireMembership[]>(body) ?? []).map(toMembership);
    },

    async getUserActivity(id: number, limit = 10): Promise<UserActivityResult> {
      // `target_type=user&target_id=N` is one person's COMPLETE authority
      // history in a single query — `user.created`/`updated`/`deleted` plus the
      // membership grants and revocations #889/#890 added. Targeting the user
      // rather than the role is what makes that one query rather than N.
      const { status, body } = await transport.request(
        'GET',
        `/api/v1/audit-logs?target_type=user&target_id=${id}&per_page=${limit}`
      );
      // 403 ⇒ the caller administers users but does not hold `audit:read`, which
      // is an ordinary configuration rather than a failure. The record page
      // OMITS the panel instead of showing an error for a capability the
      // operator deliberately did not grant.
      if (status === 403) return 'forbidden';
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch user activity (${status})`);
      }
      return unwrapData<UserActivityEntry[]>(body) ?? [];
    },

    async listRoleNames(): Promise<string[]> {
      const { status, body } = await transport.request(
        'GET',
        `/api/v1/roles?per_page=${MAX_PER_PAGE}`
      );
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch roles (${status})`);
      }
      // The role NAME, which is what `PATCH /users/{id}` resolves against a
      // tenant-visible role — the same contract the users modals follow, and the
      // reason a phantom option cannot reach the picker (WC-121).
      return (unwrapData<{ name: string }[]>(body) ?? []).map((role) => role.name);
    },

    async listOus(): Promise<OuOptionsResult> {
      // EVERY page, or none. `/api/ous` is paginated, and a short list of units
      // is indistinguishable from a correct one — acting on it moves a real
      // person into the wrong unit while the operator believes the right one was
      // never created. So an incomplete walk reports `complete: false` and the
      // screen withholds the picker rather than offering what did arrive.
      const options: OuOption[] = [];
      let page = 1;
      let total: number | null = null;

      while (page <= MAX_OU_PAGES) {
        const { status, body } = await transport.request(
          'GET',
          `/api/v1/ous?page=${page}&per_page=${MAX_PER_PAGE}`
        );
        if (!isOk(status)) {
          return { options: [], complete: false };
        }
        const rows = unwrapData<{ id: number; name: string }[]>(body) ?? [];
        for (const row of rows) options.push({ id: row.id, name: row.name });

        total = paginationTotal(body) ?? total;
        if (rows.length === 0 || (total !== null && options.length >= total)) {
          return { options, complete: total === null || options.length >= total };
        }
        page += 1;
      }

      return { options: [], complete: false };
    },

    async updateUser(id: number, input: UserUpdateInput): Promise<'ok' | 'not-found'> {
      const { status, body } = await transport.request('PATCH', `/api/v1/users/${id}`, {
        role: input.role,
        ou_id: input.ouId,
      });
      // 404 ⇒ the profile has no membership in this tenant any more; the record
      // page says so in its own words rather than surfacing a bare failure.
      if (status === 404) return 'not-found';
      if (!isOk(status)) {
        throw new Error(serverMessage(body));
      }
      return 'ok';
    },

    async sendPasswordResetLink(id: number): Promise<void> {
      const { status, body } = await transport.request(
        'POST',
        `/api/v1/users/${id}/password-reset`
      );
      if (!isOk(status)) {
        throw new Error(serverMessage(body));
      }
    },

    async getCapabilities(): Promise<string[]> {
      const { status, body } = await transport.request('GET', '/api/v1/me/capabilities');
      if (!isOk(status)) return [];
      const data = unwrapData<unknown>(body);
      if (typeof data !== 'object' || data === null || !('permissions' in data)) return [];
      const permissions = (data as { permissions: unknown }).permissions;
      if (!Array.isArray(permissions)) return [];
      return permissions.filter((p): p is string => typeof p === 'string');
    },
  };
}

/**
 * The web transport: maps a `fetch` `Response` (via the cookie-authenticated
 * `apiClient`) into the `{ status, body }` seam. JSON is read once; a body-less
 * or non-JSON response yields `body: null` (the factory tolerates it).
 */
const webTransport: Transport = {
  async request(method: string, path: string, body?: unknown) {
    const response = await apiClient(path, {
      method,
      ...(body !== undefined
        ? { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
        : {}),
    });
    const parsed: unknown = await response.json().catch(() => null);
    return { status: response.status, body: parsed };
  },
};

export const webUsersAdapter: UsersAdapter = createUsersAdapter(webTransport);
