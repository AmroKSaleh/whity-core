/**
 * Wire types for the Document templates page (A31).
 *
 * Hand-written rather than taken from `web/lib/api/schema.d.ts`: the page
 * reads two fields the generated client does not have yet (`starter_key`,
 * `legacy_template_id`, added in editor M4c), and it fetches through
 * `apiClient` like the rest of `admin/*`. Once the client is regenerated these
 * can narrow to `components['schemas']['DocumentSource']`.
 */

/** The four visibility tiers `DocumentAccessPolicy` understands. */
export type DocumentScope = 'personal' | 'tenant' | 'global' | 'system';

export const DOCUMENT_SCOPES: readonly DocumentScope[] = ['personal', 'tenant', 'global', 'system'];

/** The two kinds of editor document this page lists. */
export type TemplateKind = 'template' | 'label';

/** One of a template's own fields: a value no record type provides. */
export interface OwnField {
  key: string;
  label: string;
  sample?: string;
}

/**
 * The three columns that decide who can see a row, and who made it. The
 * audience sentence (./audience.tsx) is built from these alone.
 */
export interface GovernedRow {
  scope: DocumentScope;
  required_permission: string | null;
  owner_ou_id: number | null;
  created_by: number | null;
}

/** An editor template or label sheet, as `GET /api/v1/document-sources` lists it. */
export interface TemplateRow extends GovernedRow {
  id: number;
  kind: TemplateKind;
  title: string;
  lang: 'en' | 'ar';
  record_type: string | null;
  own_fields: OwnField[];
  draft_version: number;
  /** Null when it has never been published. */
  published_version: number | null;
  updated_at: string;
  /** Non-null for one of Whity's starters. */
  starter_key: string | null;
  /** Non-null when it was converted from the old Document designer. */
  legacy_template_id: number | null;
}

/** A unit, as `GET /api/v1/ous` lists them. */
export interface OuOption {
  id: number;
  name: string;
}

/** One entry of the permission catalogue, as `GET /api/v1/permissions` lists them. */
export interface PermissionOption {
  /** The slug, never the id: ids are not stable across installs. */
  name: string;
}

/**
 * Where the permission-tag options came from, which the scope dialog says out
 * loud because the two are not the same list: the full catalogue (readable
 * with the admin role) or the caller's own permissions.
 */
export type PermissionSource = 'catalogue' | 'own';
