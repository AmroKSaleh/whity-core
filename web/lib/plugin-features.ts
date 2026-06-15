/**
 * Plugin feature descriptors (WC-169).
 *
 * The backend exposes `GET /api/frontend/features` — an authenticated,
 * server-side permission-filtered list of UI features contributed by installed
 * plugins. The caller only ever receives features it is allowed to use, so the
 * frontend never re-implements the permission check; it just renders what it
 * is given.
 */

import { apiClient } from '@/lib/api-client';

/** A single plugin-contributed UI feature, as published by the backend. */
export interface PluginFeature {
  /** Unique kebab-case slug, also used in the /admin/x/[featureId] route. */
  id: string;
  /** Name of the plugin providing the feature (e.g. "HelloWorld"). */
  plugin: string;
  /** Human-readable label shown in headers and navigation. */
  label: string;
  /** Tabler icon kebab name (e.g. "message-circle"), or null for default. */
  icon: string | null;
  /** Navigation group the feature belongs to (e.g. "plugins"). */
  group: string;
  /** Sort order within the group. */
  order: number;
  /**
   * "crud" renders the generic schema-driven screen; "action" renders the
   * generic action form; "custom" expects a registered override.
   */
  screen: 'crud' | 'custom' | 'action';
  /** REST resource backing a crud screen; null for custom/action screens. */
  resource: {
    /** Collection endpoint, e.g. "/api/hello/greetings". */
    basePath: string;
    /** Item property naming a row in confirmations (falls back to id). */
    titleField: string | null;
  } | null;
  /** Action backing an "action" screen; null for crud/custom screens. */
  action: {
    /** HTTP method the form submits with ("POST" or "PUT"). */
    method: string;
    /** Handler endpoint the form submits to, e.g. "/api/bom/documents". */
    path: string;
    /** Submit-button label, or null for the default. */
    submitLabel: string | null;
    /** Inputs the generic form renders. */
    fields: {
      /** JSON property name the value is sent under. */
      name: string;
      /** Field label. */
      label: string;
      /** "text" | "textarea" | "file" (file is read as text into `name`). */
      kind: string;
      /** Accept filter for file inputs (e.g. ".csv"), or null. */
      accept: string | null;
      /** Whether the field is required. */
      required: boolean;
    }[];
  } | null;
  /** Permission the server used to filter this feature (informational). */
  requiredPermission: string;
  /**
   * Server-computed effective write capabilities for the caller (issue #199):
   * the renderer hides controls the caller cannot use.
   */
  capabilities: { canCreate: boolean; canEdit: boolean; canDelete: boolean };
}

/** Narrow an unknown payload to the `{ data: PluginFeature[] }` envelope. */
function isFeatureListResponse(body: unknown): body is { data: PluginFeature[] } {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return false;
  }
  return Array.isArray((body as { data: unknown }).data);
}

/**
 * Fetch the permission-filtered feature list for the current user.
 *
 * Resolves to an empty list on any failure (non-ok status, malformed body,
 * network error) — callers render "no plugin features" rather than crash.
 */
export async function fetchPluginFeatures(): Promise<PluginFeature[]> {
  try {
    const response = await apiClient('/api/frontend/features');
    if (!response.ok) {
      return [];
    }
    const body: unknown = await response.json();
    if (!isFeatureListResponse(body)) {
      return [];
    }
    return body.data;
  } catch {
    return [];
  }
}
