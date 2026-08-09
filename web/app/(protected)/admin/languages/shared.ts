/**
 * Small shared helpers for the i18n admin pages (WC-583): Languages
 * (`admin/languages`) and Translations (`admin/translations`). Both call the
 * typed API client (`@/lib/api/client`), whose failed responses carry the
 * `{ error, details? }` envelope (see `Error` in `public/openapi.json`) rather
 * than throwing — these helpers turn that into a single human-readable
 * message, mirroring `admin/settings/settings-shared.tsx`'s `errorMessage()`
 * (kept local here rather than importing from a settings-specific module).
 */

/** Narrow a `details` envelope to per-field messages, discarding non-strings. */
function fieldDetails(value: unknown): Record<string, string> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const details: Record<string, string> = {};
  for (const [key, message] of Object.entries(value as Record<string, unknown>)) {
    if (typeof message === 'string' && message !== '') {
      details[key] = message;
    }
  }
  return Object.keys(details).length > 0 ? details : null;
}

/**
 * Extract a human-friendly message from a failed typed-client call. Prefers
 * per-field `details` messages, then the top-level `error` string, then the
 * supplied fallback.
 */
export function errorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object') {
    if ('details' in error) {
      const details = fieldDetails((error as { details?: unknown }).details);
      if (details) {
        return Object.values(details).join(' ');
      }
    }
    if ('error' in error) {
      const value = (error as { error?: unknown }).error;
      if (typeof value === 'string' && value !== '') {
        return value;
      }
    }
  }
  return fallback;
}
