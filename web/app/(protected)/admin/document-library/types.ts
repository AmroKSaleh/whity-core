/**
 * Wire types for the document organizer (#978, implementing #947 item 5).
 *
 * Mirrors the `DocumentView`, `DocumentSubstrate`, `DocumentCollection` and
 * `Document` components in `CoreApiSchemas`. Hand-written rather than taken from
 * the generated client because this screen is a core admin page: the typed
 * client is generated from the DYNAMIC /api/openapi.json, which describes
 * plugin routes, so it cannot type a core resource (the same reason the
 * tag-groups and roles pages fetch through `apiClient`).
 */

/**
 * One folder in the rail.
 *
 * The two absences this type encodes are different and must stay that way:
 *
 *  - A folder the server does not send never arrives at all, and this client
 *    renders nothing in its place. #947 item 5's "awaiting me", "acted on by
 *    me" and "passed through my unit" are in that state: the routing facts they
 *    read exist, but each folder still needs a server-side predicate and
 *    registration. Rendering them empty would state "nothing awaits you" —
 *    false, and not checkable by the reader.
 *
 *  - `available: false` means the folder is real and THIS user cannot anchor it
 *    (they belong to no unit). It is rendered DISABLED with
 *    `unavailable_reason` on it, never hidden — #951, where a hidden control
 *    made three unrelated causes look identical.
 */
export interface DocumentView {
  key: string;
  /** English default from the server; translated by key where this client knows one. */
  label: string;
  description: string;
  /** `derived` (a fact about the document) or `personal` (a fact about you). */
  group: 'derived' | 'personal' | string;
  parameters: { name: string; required: boolean }[];
  requires: string[];
  available: boolean;
  unavailable_reason: string | null;
}

/** A fact source this installation does not record, and what would supply it. */
export interface DocumentSubstrate {
  key: string;
  description: string;
  provenance: string | null;
}

export interface DocumentViewsResponse {
  data: DocumentView[];
  unavailable_substrates: DocumentSubstrate[];
}

/**
 * One of the caller's own collections.
 *
 * `system_key` is `'starred'` for the collection the star control addresses and
 * null for one the user made. It is the collection's identity rather than its
 * name, which is why a keyed collection cannot be renamed or deleted.
 */
export interface DocumentCollection {
  id: number;
  tenant_id: number;
  profile_id: number;
  name: string;
  system_key: string | null;
  created_at: string;
  /** How many documents are FILED — not always how many the owner may still read. */
  item_count?: number;
}

export interface DocumentArtifact {
  id: number;
  document_id: number;
  content_type: string;
  byte_size: number;
  checksum_sha256: string;
  rendered_at: string;
  content_url: string;
}

export interface DocumentRow {
  id: number;
  template_name: string;
  title: string;
  origin_ou_id: number | null;
  created_by: number | null;
  created_at: string;
  content_url: string | null;
  artifacts: DocumentArtifact[];
  /**
   * OPTIONAL, and their absence is meaningful: only the routes that know who is
   * asking compute them. A `starred` defaulted to `false` would be a claim the
   * server never made.
   */
  collection_ids?: number[];
  starred?: boolean;
}

export interface Pagination {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

/**
 * A column the SERVER can order by.
 *
 * Deliberately not every column the table shows. `origin_ou_id` is absent
 * because the cell displays a NAME this client resolves from a separate,
 * separately-permissioned request (`ous:read`, which the plain user role does
 * not hold): ordering by the id would order rows by something the reader cannot
 * see. The server refuses anything outside this set with a 400 rather than
 * ignoring it, so a control here can never draw an indicator on a column the
 * rows are not sorted by.
 */
export type DocumentSortField = 'title' | 'created_at' | 'template_name';

export type SortDirection = 'asc' | 'desc';

/**
 * The order the server APPLIED.
 *
 * `field: null` is the order documents were recorded in, newest first — which is
 * not `created_at` under another name, because several documents issued in one
 * transaction share that timestamp exactly.
 *
 * This is read back rather than assumed: `direction` defaults per field (A→Z for
 * the text columns, newest-first for the date), so a client that assumed one
 * default would draw its arrow the wrong way round on one of the three.
 */
export interface AppliedSort {
  field: DocumentSortField | null;
  direction: SortDirection;
}

export interface DocumentListResponse {
  data: DocumentRow[];
  pagination: Pagination;
  /** Which folder ran, and the anchor the SERVER resolved (not the one we guessed). */
  view: { key: string; ou_id: number | null; collection_id: number | null };
  sort: AppliedSort;
}

/**
 * List or grid.
 *
 * Remembered in `localStorage`, which is per BROWSER rather than per person —
 * there is no per-user preference store in core, and inventing a table plus an
 * endpoint to remember a layout toggle would be a schema migration to record a
 * preference nobody would miss on a new machine. Same choice the units and
 * relations screens already make for their view toggles. Stated plainly rather
 * than described as "remembered for you", which would be a claim about the
 * account.
 */
export type LibraryLayout = 'list' | 'grid';
