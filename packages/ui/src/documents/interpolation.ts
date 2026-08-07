/**
 * Pure placeholder-token helpers shared by the document/label-designer
 * renderers (`element-content.tsx`). Extracted out of what used to be a single
 * `web/lib/documents/storage.ts` module — that module also has localStorage-
 * backed template persistence (`listSaved`/`saveTemplate`/...), which stays
 * app-side and is out of scope for this portable package; these two functions
 * had no such dependency, so they moved here and `storage.ts` now re-exports
 * them for backward compatibility.
 */

/** Substitute `{{key}}` tokens from `data` (missing keys → empty string). */
export function interpolate(text: string, data: Record<string, string>): string {
  return text.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (_m, key: string) => data[key] ?? '');
}

/** The effective value for a bindable element: bound placeholder wins, else fallback. */
export function resolveBound(
  binding: string | undefined,
  fallback: string,
  data: Record<string, string>
): string {
  if (binding && data[binding] !== undefined && data[binding] !== '') {
    return data[binding];
  }
  return fallback;
}
