/**
 * web/'s RolesAdapter implementation (Path B feature-extraction pilot) — wires
 * the data-source-agnostic `RolesScreen` (@amroksaleh/features/roles) to the
 * REST API via web's own cookie-authenticated `apiClient` (silent token refresh
 * on 401, same as every other authenticated fetch in this app).
 *
 * A desktop/Tauri client implements the exact same `RolesAdapter` against its
 * own transport (`invoke('remote_request', …)`) — only the `Transport` differs.
 * The `createRolesAdapter` factory below holds the logic BOTH clients share: the
 * `per_page=100` cap, the `{ data }` unwrap, and the 404→'not-manageable'
 * mapping (WC-110/WC-222) are written once here, over a transport-agnostic
 * `{ status, body }` seam, so the desktop adapter is just a different transport
 * passed to the same shape. Direct sibling of `web/lib/demo-catalog-adapter.ts`.
 */

import { apiClient } from '@/lib/api-client';
import type {
  Permission,
  Role,
  RoleInput,
  RoleWithPermissions,
  RolesAdapter,
  Transport,
} from '@amroksaleh/features/roles';

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
  if (typeof body === 'object' && body !== null && 'message' in body) {
    const message = (body as { message: unknown }).message;
    if (typeof message === 'string') return message;
  }
  return '';
}

/** Narrow a `/me/capabilities` payload to its permission slugs (fail-closed). */
function parsePermissionSlugs(body: unknown): string[] {
  const data = unwrapData<unknown>(body);
  if (typeof data !== 'object' || data === null || !('permissions' in data)) {
    return [];
  }
  const permissions = (data as { permissions: unknown }).permissions;
  if (!Array.isArray(permissions)) return [];
  return permissions.filter((p): p is string => typeof p === 'string');
}

/**
 * The shared adapter factory — a thin function over a {@link Transport}. Both
 * the web adapter (below) and the desktop adapter build a `RolesAdapter` by
 * passing their own transport here, so the unwrap/cap/404 logic lives in one
 * place.
 */
export function createRolesAdapter(transport: Transport): RolesAdapter {
  return {
    async listRoles(): Promise<Role[]> {
      // per_page=100: the backend has no sort/filter params, so we fetch its
      // page-size ceiling and sort/filter/paginate client-side. >100 roles are
      // silently capped (pre-existing limit).
      const { status, body } = await transport.request('GET', '/api/v1/roles?per_page=100');
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch roles (${status})`);
      }
      return unwrapData<Role[]>(body) ?? [];
    },

    async getRole(id: number): Promise<RoleWithPermissions> {
      const { status, body } = await transport.request('GET', `/api/v1/roles/${id}`);
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch role ${id} (${status})`);
      }
      const role = unwrapData<RoleWithPermissions>(body);
      if (!role) throw new Error(`Malformed role response for ${id}`);
      return role;
    },

    async getRolePermissions(id: number): Promise<Permission[]> {
      const { status, body } = await transport.request('GET', `/api/v1/roles/${id}/permissions`);
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch role permissions (${status})`);
      }
      return unwrapData<Permission[]>(body) ?? [];
    },

    async listPermissions(): Promise<Permission[]> {
      // per_page=100 — same ceiling/cap as listRoles (the picker source).
      const { status, body } = await transport.request('GET', '/api/v1/permissions?per_page=100');
      if (!isOk(status)) {
        throw new Error(serverMessage(body) || `Failed to fetch permissions (${status})`);
      }
      return unwrapData<Permission[]>(body) ?? [];
    },

    async createRole(input: RoleInput): Promise<void> {
      const { status, body } = await transport.request('POST', '/api/v1/roles', input);
      if (!isOk(status)) {
        // Surface a server validation message when present; an empty message
        // lets the caller fall back to its own translated copy.
        throw new Error(serverMessage(body));
      }
    },

    async updateRole(id: number, input: RoleInput): Promise<'ok' | 'not-manageable'> {
      const { status, body } = await transport.request('PATCH', `/api/v1/roles/${id}`, input);
      // 404 ⇒ the role is a global base role not manageable by this tenant
      // (WC-110/WC-222).
      if (status === 404) return 'not-manageable';
      if (!isOk(status)) {
        throw new Error(serverMessage(body));
      }
      return 'ok';
    },

    async deleteRole(id: number): Promise<'ok' | 'not-manageable'> {
      const { status, body } = await transport.request('DELETE', `/api/v1/roles/${id}`);
      if (status === 404) return 'not-manageable';
      if (!isOk(status)) {
        throw new Error(serverMessage(body));
      }
      return 'ok';
    },

    async getCapabilities(): Promise<string[]> {
      const { status, body } = await transport.request('GET', '/api/v1/me/capabilities');
      if (!isOk(status)) return [];
      return parsePermissionSlugs(body);
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

export const webRolesAdapter: RolesAdapter = createRolesAdapter(webTransport);
