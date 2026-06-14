/**
 * Caller capability helpers (WC-177, #205).
 *
 * The backend exposes `GET /api/me/capabilities` → `{ data: { permissions:
 * string[] } }` — the caller's authoritative, tenant-scoped, delegation-aware
 * effective permission slugs (exactly what RbacMiddleware enforces). A bespoke
 * admin page (which, unlike the schema-driven CRUD renderer of #199, has no
 * per-resource OpenAPI metadata) reads this set to HIDE write controls the
 * caller cannot use.
 *
 * The server stays authoritative: these slugs are UI hints only and grant
 * nothing. Parsing FAILS CLOSED — a malformed body yields an empty permission
 * set, so callers hide write controls rather than dangle dead affordances that
 * would 403 on submit.
 */

/** Permission required to create/edit/delete persons and relations. */
export const RELATIONS_MANAGE = 'relations:manage';

/**
 * Narrow an unknown `/api/me/capabilities` payload to its permission slugs.
 *
 * Returns `[]` for any shape that does not match `{ data: { permissions:
 * string[] } }`, keeping callers fail-closed without a cast to `any`.
 */
export function parsePermissions(body: unknown): string[] {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return [];
  }
  const data = (body as { data: unknown }).data;
  if (typeof data !== 'object' || data === null || !('permissions' in data)) {
    return [];
  }
  const permissions = (data as { permissions: unknown }).permissions;
  if (!Array.isArray(permissions)) {
    return [];
  }
  return permissions.filter((p): p is string => typeof p === 'string');
}
