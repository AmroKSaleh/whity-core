/**
 * Wire types for the templates-and-blocks governance surface.
 *
 * Hand-written rather than taken from `web/lib/api/schema.d.ts`, for the reason
 * `admin/document-library/types.ts` records: the typed client is generated from
 * the DYNAMIC `/api/openapi.json`, which describes plugin routes, so it is not a
 * dependable source of truth for a core resource — the rest of `admin/*` fetches
 * through `apiClient` with local types for exactly that reason.
 *
 * WHY THESE ARE NOT `SavedTemplate` / `DocBlock`
 * ---------------------------------------------
 * The designer already has row types for both tables
 * (`@amroksaleh/features/document-designer`), and reusing them here would be
 * wrong twice over:
 *
 *  - They DROP the governance columns. `SavedTemplate` is `{id, name, updatedAt,
 *    data}` and `DocBlock` is `{id, name, scope, w, h, elements}` — no
 *    `required_permission`, no `owner_ou_id`, no `created_by`, no `is_system`.
 *    Those five columns ARE this screen's subject matter, so a screen built on
 *    the designer's model could not show a single one of them.
 *  - Their mappers SKIP ROWS. `toSavedTemplate`/`toDocBlock` return null for a
 *    row whose `data` fails shape validation, which is right for a canvas that
 *    must not crash on a corrupt row and wrong for an inventory: a management
 *    list that quietly omits rows would disagree with the counts the server
 *    reports, and the row you cannot see is exactly the one you need to delete.
 *
 * So the two models stay separate on purpose. The designer's is about drawing;
 * this one is about who may see the drawing.
 */

/** The four visibility tiers `DocumentAccessPolicy` understands. */
export type DocumentScope = 'personal' | 'tenant' | 'global' | 'system';

export const DOCUMENT_SCOPES: readonly DocumentScope[] = [
  'personal',
  'tenant',
  'global',
  'system',
];

/**
 * The governance columns shared by `document_templates` and `document_blocks` —
 * the two tables have an identical shape, which is why the server maps them
 * through one trait and why one row type serves both tabs here.
 */
export interface GovernedRow {
  id: number;
  name: string;
  scope: DocumentScope;
  required_permission: string | null;
  owner_ou_id: number | null;
  is_system: boolean;
  created_by: number | null;
  created_at: string;
  updated_at: string;
}

/** A template row. `data` is the verbatim client DocTemplate JSON. */
export interface TemplateRow extends GovernedRow {
  data: unknown;
}

/** A block row. `data` is the verbatim client DocElement[] fragment. */
export interface BlockRow extends GovernedRow {
  data: unknown;
}

/** One template that instances a block, as `GET /document-blocks/{id}/usage` reports it. */
export interface UsageTemplate {
  id: number;
  name: string;
  scope: DocumentScope;
  required_permission: string | null;
  owner_ou_id: number | null;
  is_system: boolean;
  updated_at: string;
}

/**
 * The blast radius of changing a block.
 *
 * `total` counts EVERY referencing template in the tenant; `templates` holds
 * only the ones this caller may see, and `hidden` is the difference. The
 * asymmetry is deliberate and is the entire reason the endpoint exists — see
 * `DocumentBlocksApiHandler::usage()`.
 */
export interface BlockUsage {
  block_id: number;
  total: number;
  hidden: number;
  templates: UsageTemplate[];
}

/** A unit, as `GET /api/v1/ous` lists them. */
export interface OuOption {
  id: number;
  name: string;
}

/** One entry of the permission catalogue, as `GET /api/v1/permissions` lists them. */
export interface PermissionOption {
  /**
   * The SLUG, which is the contract. Never the id: #992 removed eight slugs and
   * left holes at ids 2, 3, 6, 7, 10, 11, 14, 15, so an id means different
   * things on installs of different ages while a name means one thing forever.
   */
  name: string;
}

/**
 * Where the permission-tag options came from, which the dialog has to say out
 * loud because the two sources are not the same list.
 *
 *  - `catalogue` — the full `GET /api/v1/permissions` catalogue. That route is
 *    gated on the `admin` ROLE, not on a permission, so a legitimate publisher
 *    who holds documents:publish without being an admin cannot read it.
 *  - `own` — the caller's own effective permission set, from
 *    `/api/me/capabilities`. Always readable, and a reasonable default: tagging
 *    a row with a permission you hold yourself means you can still see what you
 *    published, which the policy does NOT guarantee otherwise (an author's
 *    reach is waived for placement but never for the permission tag).
 */
export type PermissionSource = 'catalogue' | 'own';
