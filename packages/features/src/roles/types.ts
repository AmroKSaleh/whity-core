/**
 * Data contract for the Roles admin feature (Path B extraction pilot).
 *
 * Mirrors the DemoCatalog pilot: the presentational components in this slice
 * (`RolesScreen` and its modals) never fetch directly — every data access goes
 * through a caller-injected `RolesAdapter`. web/ wires that adapter to the REST
 * API via its cookie-authenticated `apiClient`; a desktop/Tauri client wires
 * the exact same interface to its own transport (`invoke('remote_request', …)`).
 * The adapter IMPLEMENTATIONS live OUTSIDE this package (see
 * `web/lib/roles-adapter.ts`); this file only defines the shapes.
 */

import type { RecordSectionVerdicts } from '../record/types';

export interface Permission {
  id: number;
  name: string;
  description: string;
}

export interface Role {
  id: number;
  name: string;
  description: string;
  createdAt: string;
  permissionCount?: number;
  /**
   * Whether the current tenant may update/delete this role (computed
   * server-side, mirroring the WC-110 write guard). A global NULL-tenant base
   * role is visible but NOT manageable by a regular tenant — only the SYSTEM
   * tenant may manage it. The roles admin gates Edit/Delete on this flag so a
   * non-system tenant never fires a PATCH/DELETE that would 404 (WC-222).
   */
  manageable: boolean;
  /**
   * Whether this is a GLOBAL (NULL-tenant) base role — one row shared by every
   * tenant, so editing it changes it deployment-wide (#886).
   *
   * A separate fact from {@link Role.manageable}, and not derivable from it. For
   * the SYSTEM tenant `manageable` is true for EVERY role, so the one caller
   * whose edit reaches every tenant is precisely the one for whom `!manageable`
   * says nothing — which is how a global base role used to render as "your
   * tenant's role" to the operator about to change it for the whole install.
   */
  global: boolean;
}

export interface RoleWithPermissions extends Role {
  /**
   * The role's permission set — OPTIONAL since #910, and its absence is an
   * authorization decision rather than a missing field.
   *
   * A caller without `permissions:read` does not receive this array, because the
   * record page's permissions REGION is hidden for them and a hidden region is
   * WITHHELD, not suppressed on the client. Shipping the rows and asking the
   * browser not to draw them would make the gate a rendering instruction: still
   * there for anyone who opened the network tab. The same server branch that
   * leaves the region out of {@link RoleWithPermissions.sections} leaves this
   * out, so the two cannot disagree.
   */
  permissions?: Permission[];
  /**
   * The server's per-region verdicts for THIS caller (#910).
   *
   * Keyed by region — `details`, `permissions`. A region the caller may not see
   * is absent; a region they may see carries `read-only` (with a machine reason
   * code) or `editable`. Absent entirely when the host does not resolve regions,
   * which the record page reads as "every region hidden" — fail closed, the way
   * `can()` answers while capabilities are in flight.
   *
   * Deliberately NOT flattened into booleans on this type: the record page feeds
   * it to the shell's `sectionAccessFrom`, whose whole job is that the client
   * does not recombine server answers into a decision the server never gave.
   */
  sections?: RecordSectionVerdicts;
}

/**
 * One person holding this role, as returned by
 * `GET /roles/{id}/assignments` (#882).
 *
 * `assignedAt` is the MEMBERSHIP's creation time — when this person was given
 * this role in this tenant — which is what makes a list of these an assignment
 * history rather than a roster in arbitrary order.
 */
export interface RoleAssignment {
  membershipId: number;
  profileId: number;
  tenantId: number;
  displayName: string;
  /** Null when the profile carries no primary email row; they still hold the role. */
  email: string | null;
  ouId: number | null;
  isPrimary: boolean;
  status: string;
  assignedAt: string | null;
}

/**
 * A page of holders plus the FULL headcount.
 *
 * The two travel together because they come from one request: `total` is the
 * pagination total of the same query that produced `assignments`. Splitting them
 * would invite a caller to count `assignments.length` — which is the page size,
 * not the answer.
 */
export interface RoleAssignmentsPage {
  assignments: RoleAssignment[];
  total: number;
}

/**
 * One audit entry about this role, from `GET /audit-logs?target_type=role&target_id=…`.
 *
 * A structural subset of the audit-log contract: the record page shows what
 * happened, when, and by whom, and has no use for `ipAddress` or `tenantId`.
 */
export interface RoleActivityEntry {
  id: number;
  action: string;
  actorUserId: number | null;
  createdAt: string | null;
  metadata: Record<string, unknown>;
}

/**
 * The audit trail is gated on `audit:read`, which a role administrator need not
 * hold. `'forbidden'` is the same shape of sentinel as `updateRole`'s
 * `'not-manageable'`: an EXPECTED refusal the UI renders as an absent section,
 * distinct from a failure it should surface. Anything else throws.
 */
export type RoleActivityResult = RoleActivityEntry[] | 'forbidden';

/**
 * The transport-agnostic seam every client implements. Defined once in the
 * record slice now that a SECOND adapter (users, #882) is written over it, and
 * re-exported here so `@amroksaleh/features/roles`' published surface is
 * unchanged for everything that already imports it from this module.
 */
export type { Transport, TransportResponse } from '../record/transport';

/**
 * The per-region verdict map, re-exported from the record slice so a consumer of
 * `@amroksaleh/features/roles` needs one import rather than two.
 */
export type { RecordSectionDenial, RecordSectionVerdicts } from '../record/types';

/** The fields a caller may set when creating or updating a role. */
export interface RoleInput {
  name: string;
  description: string;
  permissions: number[];
}

/**
 * The fields an UPDATE may carry — every one of them optional (#910).
 *
 * A record page whose regions are gated separately sends only the regions the
 * caller may write: the details, the permission set, either, or both. The server
 * refuses a key the caller's region gate does not allow rather than dropping it,
 * so a client that always sent all three would turn a read-only REGION into a
 * failed PAGE.
 *
 * Distinct from {@link RoleInput}, which CREATE still uses whole: a new role is
 * not composed of regions somebody may or may not be allowed to fill in — it
 * either gets created with a name, a description and a permission set, or it is
 * not created.
 */
export type RoleUpdateInput = Partial<RoleInput>;

/**
 * One tenant a system operator may create a role for (#888).
 *
 * The name is what a person picks from; the id is what `POST /roles` is given.
 */
export interface RoleTenantOption {
  id: number;
  name: string;
}

/**
 * Where a new role is to live (#888).
 *
 *  - `'own'`   — the caller's own tenant. The default, and the ONLY value a
 *                non-system caller can produce: it sends no scope fields at all,
 *                so the request is byte-identical to what it always was.
 *  - `'global'`— a shared NULL-tenant base role (`global: true`).
 *  - a number  — that tenant's own role (`tenant_id: n`).
 *
 * Modelled as one closed value rather than two independent optional fields
 * because the UI must not be able to express the states the server rejects —
 * "both" and "explicitly nothing" — in the first place.
 */
export type RoleScope = 'own' | 'global' | number;

/**
 * The create seam a HOST supplies when its caller may choose a scope (#888) —
 * in practice, when the caller is the system tenant.
 *
 * OPTIONAL, and its absence is the ordinary state rather than a broken one: the
 * package is mounted by a Next app, a Tauri shell and a Vite harness, and none
 * of them shares an identity or tenants API. A host that supplies it gets the
 * scope picker; a host that omits it creates in its own tenant exactly as
 * before. Same shape of opt-in as `onOpenRecord` — revertible by deleting one
 * prop at one call site.
 */
export interface RoleScopeSeam {
  /**
   * The tenants that may be chosen, loaded when the create modal opens rather
   * than on every list render.
   */
  loadTenants: () => Promise<RoleTenantOption[]>;
}

/**
 * Create adds an optional target SCOPE to {@link RoleInput} (#888). Update does
 * not: a role's owner is settled at create and never moves, because re-homing
 * one would silently revoke it from everyone holding it in the old tenant.
 */
export interface RoleCreateInput extends RoleInput {
  scope?: RoleScope;
}

/**
 * One page of `GET /roles`, as the screen asks for it (#1102).
 *
 * Structurally the `DataTableQueryRequest` the roles screen's table produces,
 * restated here rather than imported so this contract stays about the ENDPOINT
 * rather than about the component that happens to drive it — a Flutter or CLI
 * consumer implements the same adapter and has no DataTable at all.
 *
 * `sort` is the endpoint's own key (`name`, `description`, `created`) and `dir`
 * travels with it: both absent means "your default order", which is what the
 * screen sends when no column is chosen. `q` absent means no search. None of
 * the three is ever sent empty — an empty `sort` would ask the endpoint to
 * order by nothing in particular and get a fallback the UI would then present
 * as the sort it asked for.
 */
export interface RoleListQuery {
  /** 1-based. */
  page: number;
  perPage: number;
  sort?: string;
  dir?: 'asc' | 'desc';
  q?: string;
}

/**
 * A page of roles and the FULL count, from the one request that produced both.
 *
 * Same bargain as {@link RoleAssignmentsPage}: `total` is every role the query
 * matches, `roles` is only the page. Counting `roles.length` would be counting
 * the page size, which is how a list ends up drawing one page of controls for a
 * thousand rows.
 */
export interface RoleListPage {
  roles: Role[];
  total: number;
  totalPages: number;
}

/**
 * The injected data-source adapter the components consume. Both the web and
 * desktop factories are thin wrappers over a {@link Transport}, so the
 * per_page=100 cap, the `{data}` unwrap and the 404→'not-manageable' mapping
 * are written once per client and never leak into the components.
 */
export interface RolesAdapter {
  /**
   * GET /roles — ONE page, ordered and filtered by the server (#1102).
   *
   * Took no arguments and answered `Role[]` until then: it fetched
   * `per_page=100` and the screen sorted, searched and paged that slice in the
   * browser, so a tenant with more than a hundred roles could not see the rest
   * and nothing on screen said so. The query is REQUIRED rather than optional
   * because there is no sensible "whatever you like" page for a list a person
   * is looking at, and an optional one would let a caller silently reintroduce
   * the first page as the whole answer.
   */
  listRoles(query: RoleListQuery): Promise<RoleListPage>;
  /** GET /roles/{id} — returns the role with its full permission list. */
  getRole(id: number): Promise<RoleWithPermissions>;
  /** GET /roles/{id}/permissions — the read-only grouped view. */
  getRolePermissions(id: number): Promise<Permission[]>;
  /**
   * GET /roles/{id}/assignments — who holds this role, newest grant first,
   * with the full headcount (#882). `limit` caps the PAGE, never the count.
   */
  getRoleAssignments(id: number, limit?: number): Promise<RoleAssignmentsPage>;
  /**
   * GET /audit-logs?target_type=role&target_id={id} — this role's own history.
   * Resolves `'forbidden'` when the caller lacks `audit:read` (see
   * {@link RoleActivityResult}).
   */
  getRoleActivity(id: number, limit?: number): Promise<RoleActivityResult>;
  /** GET /permissions?per_page=100 (capped) — the picker source. */
  listPermissions(): Promise<Permission[]>;
  /**
   * POST /roles. `input.scope` names the tenant to create IN, or asks for a
   * global base role; omitted (and for every non-system caller) the request
   * carries no scope fields and the server stamps the caller's own tenant.
   */
  createRole(input: RoleCreateInput): Promise<void>;
  /**
   * PATCH /roles/{id}; a 404 maps to 'not-manageable' (WC-110/WC-222).
   *
   * Takes a PARTIAL body since #910 — see {@link RoleUpdateInput}. A 403 still
   * throws, and should: it means the caller sent a region they may not write,
   * which is a client bug the page should surface rather than absorb.
   */
  updateRole(id: number, input: RoleUpdateInput): Promise<'ok' | 'not-manageable'>;
  /** DELETE /roles/{id}; a 404 maps to 'not-manageable' (WC-110/WC-222). */
  deleteRole(id: number): Promise<'ok' | 'not-manageable'>;
  /** GET /me/capabilities — the caller's effective permission slugs, for building `can`. */
  getCapabilities(): Promise<string[]>;
}

/**
 * The translation function a screen calls — identical in shape to
 * `@amroksaleh/features/i18n`'s `TranslateFn`, redeclared here so this slice
 * stays self-contained (no dependency on the i18n runtime, which the desktop
 * client does not mount). Callers pass their own; it defaults to
 * `identityTranslate` when omitted.
 */
export type TranslateFn = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>
) => string;

/**
 * Structural alias of {@link TranslateFn} used on the injected `t` params and
 * locals INSIDE this slice's components. Named distinctly on purpose: the i18n
 * catalogue extractor binds any `x: TranslateFn` parameter as a translate call
 * and then needs a single `useTranslation('domain')` in the file to resolve its
 * domain — which these prop-driven components deliberately don't have. Typing
 * `t` as `RolesTranslate` keeps the extractor from scanning these files (the
 * same way DemoCatalog stays unscanned via `NavTranslate`); the keys are
 * declared instead through file-scoped `@i18n-keys` blocks. The public
 * `RolesScreenProps.t` keeps the `TranslateFn` name so the export surface is
 * unchanged — the two are the same type.
 */
export type RolesTranslate = TranslateFn;

export interface RolesScreenProps {
  /** Injected data-source adapter (server api-client on web, remote transport on desktop). */
  adapter: RolesAdapter;
  /** Resolved, fail-closed capability check (web wraps useCapabilities; desktop fetches /me/capabilities). */
  can: (capability: string) => boolean;
  /** Optional translator; defaults to identity (keys render as literals) when omitted. */
  t?: TranslateFn;
  /** Optional notifier; web wires ToastProvider, desktop wires its own. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  /**
   * Navigate to a role's RECORD PAGE. The navigation seam: this package is
   * mounted by a Next app, a Tauri shell and a Vite harness, none of which share
   * a router, so the screen asks its host to navigate rather than importing one.
   *
   * REQUIRED since #910, where it was optional under #882. It was optional
   * because the edit MODAL was still the fallback, and the modal is now gone: a
   * `max-w-3xl` dialog wrapping a `max-h-80` scroll region holding 53+
   * permissions was the acute case that produced #882, and it cannot express the
   * three per-region states #910 requires — a dialog has one gate, one set of
   * inputs, and nowhere to say why half of them are absent. Making the seam
   * required is the honest consequence: a host that mounts this screen must be
   * able to navigate to a record, because that is where editing now happens.
   */
  onOpenRecord: (role: Role) => void;
  /**
   * Cross-tenant create seam (#888). Supplied only by a host whose caller may
   * choose where a new role lives; omitted, the Create modal has no scope picker
   * and posts no scope fields. See {@link RoleScopeSeam}.
   */
  scope?: RoleScopeSeam;
  className?: string;
}

export interface RoleRecordScreenProps {
  /** Injected data-source adapter (server api-client on web, remote transport on desktop). */
  adapter: RolesAdapter;
  /** The role this page is about — the route's dynamic segment, resolved by the host. */
  roleId: number;
  /**
   * NO `can` PROP, and its absence is the design (#910).
   *
   * It took one until #910: the screen read `can('roles:write')` and folded it
   * with the record's `manageable` flag into a page-level binary. Both were
   * server answers, but the FOLD was the client's, and a page whose regions are
   * governed separately would have needed the client to invent one conjunction
   * per region — a browser holding an opinion about authorization that the
   * deployment never granted.
   *
   * `GET /roles/{id}` now carries a verdict per region instead, so there is
   * nothing left for a capability check to decide here. It also means this page
   * no longer has to wait for `/me/capabilities` before it can render: the
   * record's own response is the whole answer.
   */
  /** Optional translator; defaults to identity (keys render as literals) when omitted. */
  t?: TranslateFn;
  /** Optional notifier; web wires ToastProvider, desktop wires its own. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  /** Navigate back to the roles list (host-supplied, same seam as `onOpenRecord`). */
  onBack: () => void;
  className?: string;
}
