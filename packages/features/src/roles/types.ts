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
}

export interface RoleWithPermissions extends Role {
  permissions: Permission[];
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

/** A single transport round-trip result. */
export interface TransportResponse {
  /** HTTP-equivalent status code. */
  status: number;
  /** Parsed JSON response body (or `null` when there was no body). */
  body: unknown;
}

/**
 * The transport-agnostic seam every client implements. `{status, body}` is the
 * natural least-common-denominator: the desktop side's Rust command already
 * returns exactly this shape, and the web adapter maps a `fetch` `Response`
 * into it. A `RolesAdapter` is a thin function over one of these.
 */
export interface Transport {
  /** method + app-relative path (e.g. "/api/v1/roles?per_page=100"); JSON body optional. */
  request(method: string, path: string, body?: unknown): Promise<TransportResponse>;
}

/** The fields a caller may set when creating or updating a role. */
export interface RoleInput {
  name: string;
  description: string;
  permissions: number[];
}

/**
 * The injected data-source adapter the components consume. Both the web and
 * desktop factories are thin wrappers over a {@link Transport}, so the
 * per_page=100 cap, the `{data}` unwrap and the 404→'not-manageable' mapping
 * are written once per client and never leak into the components.
 */
export interface RolesAdapter {
  /** GET /roles?per_page=100 (capped — see the truncation note in roles-screen). */
  listRoles(): Promise<Role[]>;
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
  /** POST /roles. */
  createRole(input: RoleInput): Promise<void>;
  /** PATCH /roles/{id}; a 404 maps to 'not-manageable' (WC-110/WC-222). */
  updateRole(id: number, input: RoleInput): Promise<'ok' | 'not-manageable'>;
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
   * Navigate to a role's RECORD PAGE (#882). The navigation seam: this package
   * is mounted by a Next app, a Tauri shell and a Vite harness, none of which
   * share a router, so the screen asks its host to navigate rather than
   * importing one.
   *
   * OPTIONAL, and its absence is the fallback rather than a broken state: a host
   * that supplies it routes Edit to the record page, a host that does not keeps
   * today's edit MODAL, unchanged. That is what makes the record page additive —
   * revertible by deleting one prop at one call site — and it is why the modal
   * is still in this package.
   */
  onOpenRecord?: (role: Role) => void;
  className?: string;
}

export interface RoleRecordScreenProps {
  /** Injected data-source adapter (server api-client on web, remote transport on desktop). */
  adapter: RolesAdapter;
  /** The role this page is about — the route's dynamic segment, resolved by the host. */
  roleId: number;
  /** Resolved, fail-closed capability check. */
  can: (capability: string) => boolean;
  /** Optional translator; defaults to identity (keys render as literals) when omitted. */
  t?: TranslateFn;
  /** Optional notifier; web wires ToastProvider, desktop wires its own. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  /** Navigate back to the roles list (host-supplied, same seam as `onOpenRecord`). */
  onBack: () => void;
  className?: string;
}
