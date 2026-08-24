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
 * Permissions for user management.
 *
 * `USERS_READ` is here for the invitations panel (WHIT-417), which is the first
 * UI surface whose READ half is gated separately — the Users list itself sits
 * behind a route the reader already reached.
 */
export const USERS_READ = 'users:read';
export const USERS_WRITE = 'users:write';
export const USERS_DELETE = 'users:delete';

/** Permissions for role management. */
export const ROLES_WRITE = 'roles:write';
export const ROLES_DELETE = 'roles:delete';

/** Permissions for organizational unit management. */
export const OUS_WRITE = 'ous:write';
export const OUS_DELETE = 'ous:delete';

/** Permissions for tenant management. */
export const TENANTS_WRITE = 'tenants:write';
export const TENANTS_DELETE = 'tenants:delete';

/** Permission for AI principal (MCP credential) management (WC-0208ce4d). */
export const MCP_TOKENS_MANAGE = 'mcp:tokens:manage';

/** Permissions for the native taxonomy/tagging subsystem (WC-621). */
export const TAGS_READ = 'tags:read';
export const TAGS_MANAGE = 'tags:manage';

/**
 * i18n admin management (WC-583). LANGUAGES_MANAGE is a PLATFORM capability —
 * the backend additionally requires the caller to be acting in the SYSTEM
 * tenant (id 0), since languages carry no tenant_id column at all. A regular
 * tenant may still hold this permission (it inherits the shared global
 * `admin` role) but every write 403s outside the system tenant — gate the UI
 * on `isSystemTenant` too (see admin/languages/page.tsx).
 * TRANSLATIONS_MANAGE is tenant-scoped: any tenant holding it may create/
 * update/delete its OWN translation rows.
 */
export const LANGUAGES_MANAGE = 'languages:manage';
export const TRANSLATIONS_MANAGE = 'translations:manage';

/**
 * Putting an issued document into circulation (#947 item 3, migration 113).
 *
 * The ONE capability routing adds, and the only one it needs. Three things about
 * it are worth stating where the constant lives, because each is a trap:
 *
 *  - It is resolved by NAME, like every slug in this file, and never by id. #992
 *    removed eight slugs (`users:create/update`, `roles:create/update`,
 *    `tenants:create/update`, `ous:create/update`) which held ids 2, 3, 6, 7,
 *    10, 11, 14, 15 — so the low id range has holes and an id is not stable
 *    across installs of different ages.
 *
 *  - It gates STARTING a route only. ACTING on one — forward, acknowledge,
 *    return, note — is deliberately unpermissioned: being a recipient IS the
 *    authorization, and requiring a grant on top would let a route resolve to
 *    somebody who then cannot answer it, leaving the item open forever. So do
 *    NOT gate the act controls on this.
 *
 *  - It is HELD. Migration 113 grants it to every role holding
 *    `documents:render` rather than to the `admin` role by name, so a custom
 *    administrative role does not silently lose it on upgrade. Verified against
 *    a freshly migrated schema before gating anything on it — the check that
 *    `roles:read` failed for months.
 */
export const DOCUMENTS_ROUTE = 'documents:route';

/**
 * Bringing a document into existence — `POST /api/v1/documents` (#947 item 1).
 *
 * NO `documents:create` WAS MINTED, and that is the decision worth recording
 * where the constant lives, because the obvious-looking alternative is a
 * lockout. Migration 113 already answered "who may raise a document" when it
 * chose the audience for `documents:route`: *"`documents:render` is what gates
 * `persist: true` on the render routes, so a role holding it is precisely a role
 * that can bring a document into existence"*. A new slug would be a second
 * answer to that question — and on every install that already exists it would
 * be a permission NOBODY HOLDS, so the New button would be hidden for the
 * seeded `admin` role until somebody wrote a grant migration. A catalogue row is
 * not a holder.
 *
 * IT IS HELD. Migration 060 grants `documents:render` to the seeded `admin`
 * role; four of the five roles in the document demo fixture hold it (the
 * exception, `demo-secretary`, is the deliberate negative case). Checked against
 * a freshly migrated schema before this button was gated on it.
 *
 * IT DOES NOT MEAN THE RENDER TIER IS RUNNING. The permission and the
 * `documents.render_enabled` setting answer different questions: this is "may
 * you raise a document", that is "can this instance produce a PDF". A holder can
 * create documents on an instance with no render container at all — the record
 * is the deliverable and the artifact is opportunistic. So do NOT try to infer
 * one from the other.
 */
export const DOCUMENTS_RENDER = 'documents:render';

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
