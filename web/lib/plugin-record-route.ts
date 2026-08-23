/**
 * The address of a plugin record (#948).
 *
 * `/admin/x/[featureId]` was the only plugin route in the app, so every
 * single-record surface a plugin owned was an overlay over its list: a record
 * could not be linked to, bookmarked, or returned to with the back button, and
 * `{record}` — the reserved master-detail binding a host is supposed to seed
 * with "the record this ROUTE is about" (SDK 1.33, BlockValidator's
 * PAGE_RECORD_BINDING) — had nothing to bind to in a real session. This module
 * is the one place that spells that address, so the list that links to it and
 * the route that reads it cannot drift.
 *
 * Not in `plugin-features.ts`: that module is the feature PAYLOAD, and this is
 * a fact about the web app's own routing table, which the desktop host answers
 * differently.
 */

/** The route segment prefix every plugin feature screen lives under. */
const FEATURE_BASE = '/admin/x';

/**
 * The href of one record of one feature.
 *
 * Both segments are encoded. A feature id is a kebab-case slug the host
 * validated, but a RECORD id is plugin data — a uuid, a slug, or anything else
 * the plugin's own resource uses as a key — and an unencoded `/` or `?` in one
 * would silently address a different route.
 */
export function recordHref(featureId: string, recordId: string | number): string {
  return `${FEATURE_BASE}/${encodeURIComponent(featureId)}/${encodeURIComponent(String(recordId))}`;
}

/** The href of a feature's own screen — where a record page's back control goes. */
export function featureHref(featureId: string): string {
  return `${FEATURE_BASE}/${encodeURIComponent(featureId)}`;
}
