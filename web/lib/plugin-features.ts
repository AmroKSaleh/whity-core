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

/**
 * The SP1 server-driven plugin-UI block set (WC-225/226/227), mirrored from the
 * SDK's `BlockContract` as a TypeScript discriminated union keyed on `type`.
 *
 * A plugin describes a screen as a platform-NEUTRAL tree of semantic UI blocks;
 * the host validates and serves it verbatim, and each platform's renderer maps
 * the semantics to native widgets. Props are semantic, never presentational —
 * no CSS classes, colors, or pixels ever cross this boundary.
 *
 * Containers carry a `children: Block[]` array; leaves do not. The `type`
 * literal is the discriminant.
 */

/** Container: a labelled vertical grouping of blocks. */
export interface SectionBlock {
  type: 'section';
  title?: string;
  children: Block[];
}

/** Container: a surface with an optional title/description and a body. */
export interface CardBlock {
  type: 'card';
  title?: string;
  description?: string;
  children: Block[];
}

/** Container: an N-column responsive grid of blocks. */
export interface GridBlock {
  type: 'grid';
  columns: 1 | 2 | 3 | 4;
  children: Block[];
}

/** Container: a horizontal row with an optional main-axis alignment. */
export interface RowBlock {
  type: 'row';
  align?: 'start' | 'center' | 'end' | 'between';
  children: Block[];
}

/** Container: a tab set whose children are `tab` blocks. */
export interface TabsBlock {
  type: 'tabs';
  children: TabBlock[];
}

/** Container: one labelled tab panel; only valid as a child of `tabs`. */
export interface TabBlock {
  type: 'tab';
  label: string;
  children: Block[];
}

/** Leaf: a horizontal separator. */
export interface DividerBlock {
  type: 'divider';
}

/** Leaf: a semantic heading at one of four levels. */
export interface HeadingBlock {
  type: 'heading';
  level: 1 | 2 | 3 | 4;
  text: string;
}

/** Leaf: a paragraph of text, optionally muted. */
export interface TextBlock {
  type: 'text';
  value: string;
  tone?: 'default' | 'muted';
}

/** Leaf: a callout banner with a semantic variant. */
export interface AlertBlock {
  type: 'alert';
  variant: 'info' | 'success' | 'warning' | 'danger';
  title?: string;
  body: string;
}

/** Leaf: a small status pill. */
export interface BadgeBlock {
  type: 'badge';
  variant: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
  label: string;
}

/** Leaf: a single metric tile with an optional hint and trend. */
export interface StatBlock {
  type: 'stat';
  label: string;
  value: string;
  hint?: string;
  trend?: 'up' | 'down' | 'flat';
}

/** Leaf: a definition list of label/value pairs. */
export interface KeyValueBlock {
  type: 'keyValue';
  items: { label: string; value: string }[];
}

/** Leaf: an ordered or unordered list of plain strings. */
export interface ListBlock {
  type: 'list';
  ordered?: boolean;
  items: string[];
}

/** Leaf: a static table of string cells keyed by column. */
export interface TableBlock {
  type: 'table';
  columns: { key: string; label: string }[];
  rows: Record<string, string>[];
}

/** Leaf: a labelled action that links to an internal route. */
export interface ButtonBlock {
  type: 'button';
  label: string;
  href: string;
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
}

/** Leaf: a Tabler icon referenced by name. */
export interface IconBlock {
  type: 'icon';
  name: string;
  tone?: 'default' | 'muted';
}

/** Leaf: a monospaced code sample, rendered as literal text. */
export interface CodeBlock {
  type: 'code';
  language?: string;
  content: string;
}

/**
 * The discriminated union of every SP1 block, keyed on `type`. The host has
 * already validated the tree, but the web renderer revalidates defensively so a
 * malformed node degrades to a placeholder rather than crashing.
 */
export type Block =
  | SectionBlock
  | CardBlock
  | GridBlock
  | RowBlock
  | TabsBlock
  | TabBlock
  | DividerBlock
  | HeadingBlock
  | TextBlock
  | AlertBlock
  | BadgeBlock
  | StatBlock
  | KeyValueBlock
  | ListBlock
  | TableBlock
  | ButtonBlock
  | IconBlock
  | CodeBlock;

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
   * generic action form; "blocks" renders a platform-neutral block tree;
   * "custom" expects a registered override.
   */
  screen: 'crud' | 'custom' | 'action' | 'blocks';
  /** REST resource backing a crud screen; null for custom/action screens. */
  resource: {
    /** Collection endpoint, e.g. "/api/v1/hello/greetings". */
    basePath: string;
    /** Item property naming a row in confirmations (falls back to id). */
    titleField: string | null;
  } | null;
  /** Action backing an "action" screen; null for crud/custom screens. */
  action: {
    /** HTTP method the form submits with ("POST" or "PUT"). */
    method: string;
    /** Handler endpoint the form submits to, e.g. "/api/v1/bom/documents". */
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
      kind: 'text' | 'textarea' | 'file';
      /** Accept filter for file inputs (e.g. ".csv"), or null. */
      accept: string | null;
      /** Whether the field is required. */
      required: boolean;
    }[];
  } | null;
  /**
   * Block tree backing a `screen: 'blocks'` feature; absent for other screens.
   * The host has already validated this against the SDK block whitelist.
   */
  blocks?: Block[];
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
    const response = await apiClient('/api/v1/frontend/features');
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
