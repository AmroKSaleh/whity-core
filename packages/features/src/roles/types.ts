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

export interface RolesScreenProps {
  /** Injected data-source adapter (server api-client on web, remote transport on desktop). */
  adapter: RolesAdapter;
  /** Resolved, fail-closed capability check (web wraps useCapabilities; desktop fetches /me/capabilities). */
  can: (capability: string) => boolean;
  /** Optional translator; defaults to identity (keys render as literals) when omitted. */
  t?: TranslateFn;
  /** Optional notifier; web wires ToastProvider, desktop wires its own. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  className?: string;
}
