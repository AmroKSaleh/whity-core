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

/**
 * WC-532 A3: a presentational conditional-visibility predicate. When a block
 * carrying `visibleWhen` is inside a `form`, the renderer hides it unless the
 * referenced sibling field matches. Render-time only — it never affects the
 * submitted payload or server-side validation. Mirrors the SDK
 * `visibilityRule` prop type; the SDK validator requires exactly one of
 * `equals` / `in`.
 */
export interface VisibleWhen {
  field: string;
  equals?: string | number | boolean;
  in?: (string | number | boolean)[];
}

/** Container: a labelled vertical grouping of blocks. */
export interface SectionBlock {
  type: 'section';
  title?: string;
  visibleWhen?: VisibleWhen;
  children: Block[];
}

/** Container: a surface with an optional title/description and a body. */
export interface CardBlock {
  type: 'card';
  title?: string;
  description?: string;
  visibleWhen?: VisibleWhen;
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

/** WC-532 A5: a LaTeX expression rendered via KaTeX (inline unless `block`). */
export interface MathBlock {
  type: 'math';
  expression: string;
  block?: boolean;
}

/** WC-532 A5: Markdown source rendered by the XSS-safe renderer (with $…$ math). */
export interface MarkdownBlock {
  type: 'markdown';
  content: string;
}

// ---- SP2 data-bound leaf blocks (WC-231) ----

/**
 * Leaf: a table whose rows are fetched at runtime from `source`.
 * `source` is the versioned API path served by the host (e.g. `/api/v1/x/rows`).
 *
 * WC-241: a column may set `sortable`/`filterable` to turn on inline
 * client-side sort/a per-column text filter for it; `pageSize` turns on
 * client-side pagination. All three apply ONLY to the rows already fetched
 * from `source` — none of them trigger a second request or touch any other
 * route.
 */
/**
 * WC-532 A1: a per-row affordance in a dataTable's trailing Actions column.
 * Either an internal-nav `href` or a `{method, endpoint}` mutation — both
 * templated with `{field}` placeholders substituted from the row at render.
 */
export type RowAction =
  | { label: string; href: string }
  | { label: string; method: 'POST' | 'PUT' | 'DELETE'; endpoint: string; confirm?: string }
  // WC-block-modal-drawer: open a modal/drawer by its `id`, publishing this row
  // into the master-detail context under that id for the overlay's content to
  // read (a form input via `defaultFrom`, a data-bound child via `params.from`).
  | { label: string; open: string };

/**
 * WC-532 A7 (master-detail): binds a query `param` on a data-bound block's
 * `source` to the current value of the `selector` named `from`. The renderer
 * appends `?param=<selection>` (URL-encoded) at fetch time; the base `source`
 * is unchanged (still ownership-checked).
 */
export interface SourceParam {
  param: string;
  from: string;
}

export interface DataTableBlock {
  type: 'dataTable';
  source: string;
  columns: { key: string; label: string; sortable?: boolean; filterable?: boolean }[];
  pageSize?: number;
  emptyText?: string;
  rowActions?: RowAction[];
  params?: SourceParam[];
}

/**
 * Leaf: a single metric tile whose value is fetched at runtime from `source`.
 */
export interface DataStatBlock {
  type: 'dataStat';
  source: string;
  label: string;
  valueField: string;
  hintField?: string;
  trendField?: string;
  emptyText?: string;
  params?: SourceParam[];
}

/**
 * Leaf: an ordered or unordered list whose items are fetched at runtime from `source`.
 *
 * WC-241: `sortable` turns on an alphabetical asc/desc toggle; `filterable`
 * turns on a search box over `itemField`; `pageSize` turns on client-side
 * pagination. All three apply to the rows already fetched from `source`.
 */
export interface DataListBlock {
  type: 'dataList';
  source: string;
  itemField: string;
  ordered?: boolean;
  sortable?: boolean;
  filterable?: boolean;
  pageSize?: number;
  emptyText?: string;
  params?: SourceParam[];
}

// ---- SP3 interactive blocks (WC-235) ----

/**
 * Container: a form that collects inputs and submits them as JSON to `submit.endpoint`.
 * The host has already validated and version-rewritten `submit.endpoint`.
 */
export interface FormBlock {
  type: 'form';
  submit: { method: 'POST' | 'PUT'; endpoint: string };
  /** If present, the form GETs this path on mount and pre-populates fields with the response. */
  dataSource?: { method: 'GET'; path: string };
  requiredPermission?: string;
  children: Block[];
}

/**
 * WC-532 A2: a repeatable field-group (form only). `children` is the per-row
 * sub-form template; the renderer collects the rows into a JSON array submitted
 * under `name`. `min`/`max` bound the row count; `itemLabel` names each row.
 */
export interface FieldArrayBlock {
  type: 'fieldArray';
  name: string;
  label: string;
  itemLabel?: string;
  min?: number;
  max?: number;
  children: Block[];
}

/** Leaf (form only): a single-line text input. */
export interface TextInputBlock {
  type: 'textInput';
  name: string;
  label: string;
  placeholder?: string;
  required?: boolean;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  /** When true, renders as type="password". The sentinel value '••••••' is never sent on submit. */
  sensitive?: boolean;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a multi-line text area. */
export interface TextAreaBlock {
  type: 'textArea';
  name: string;
  label: string;
  rows?: number;
  required?: boolean;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/**
 * WC-532 A5: a Markdown-aware multi-line input. Submits Markdown source (a
 * plain string) like textArea; the renderer shows a live preview via the
 * XSS-safe renderer.
 */
export interface RichTextInputBlock {
  type: 'richTextInput';
  name: string;
  label: string;
  rows?: number;
  required?: boolean;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a numeric input. */
export interface NumberInputBlock {
  type: 'numberInput';
  name: string;
  label: string;
  min?: number;
  max?: number;
  step?: number;
  required?: boolean;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a single-select dropdown. */
export interface SelectBlock {
  type: 'select';
  name: string;
  label: string;
  options: { value: string; label: string }[];
  required?: boolean;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a boolean checkbox. */
export interface CheckboxBlock {
  type: 'checkbox';
  name: string;
  label: string;
  default?: boolean;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a range slider. */
export interface SliderBlock {
  type: 'slider';
  name: string;
  label: string;
  min: number;
  max: number;
  step?: number;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a date input. */
export interface DateInputBlock {
  type: 'dateInput';
  name: string;
  label: string;
  required?: boolean;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a file input. Without encoding the content is read as text; with 'base64' it is encoded as a data URI. */
export interface FileInputBlock {
  type: 'fileInput';
  name: string;
  label: string;
  accept?: string;
  required?: boolean;
  /** When 'base64', the file is converted to a data URI via FileReader.readAsDataURL() before submit. */
  encoding?: 'base64';
  visibleWhen?: VisibleWhen;
}

/** Leaf (form only): a colour picker. */
export interface ColorInputBlock {
  type: 'colorInput';
  name: string;
  label: string;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
  visibleWhen?: VisibleWhen;
}

/**
 * The `{ar?, en?}` value a bilingual field reads and submits — the same
 * LocalizedText convention the schema-driven CRUD screens use (WC-532).
 */
export interface LocalizedTextValue {
  ar?: string;
  en?: string;
  [key: string]: string | undefined;
}

/**
 * WC-532 A4: a paired Arabic/English bilingual text input. Renders the shared
 * RTL/LTR-synced `BilingualInput` and submits a `{ar?, en?}` object under
 * `name` — matching how CRUD screens render `x-whity-localized-text` fields.
 */
export interface BilingualTextInputBlock {
  type: 'bilingualText';
  name: string;
  label: string;
  required?: boolean;
  arLabel?: string;
  enLabel?: string;
}

/**
 * WC-532 A6: a foreign-key / reference select. Unlike `select` (static
 * `options`), it populates its dropdown from a resource COLLECTION fetched from
 * `source` (an ownership-checked, version-rewritten apiPath). Each row's
 * `valueField` is the submitted value; `labelField` is the display text.
 */
export interface ReferenceSelectBlock {
  type: 'referenceSelect';
  name: string;
  label: string;
  source: string;
  valueField: string;
  labelField: string;
  required?: boolean;
  placeholder?: string;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
}

/** Leaf (form only): triggers form submission. */
export interface SubmitButtonBlock {
  type: 'submitButton';
  label: string;
  requiredPermission?: string;
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
}

/** Leaf (standalone): a one-click mutating action button. */
export interface ActionButtonBlock {
  type: 'actionButton';
  label: string;
  action: { method: 'POST' | 'PUT'; endpoint: string };
  requiredPermission?: string;
  confirm?: string;
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
}

// ---- SP4 chart block (WC-240) ----

/**
 * Leaf: a bar/line/area/pie chart whose rows are fetched at runtime from
 * `source`. Each series picks one of the five semantic `--chart-1..5` design
 * tokens by number — never a raw hex/rgb value, so a plugin cannot smuggle
 * CSS through this prop. `xField` names the category/label field in each row
 * (the x-axis for bar/line/area, the slice label for pie).
 */
export interface ChartBlock {
  type: 'chart';
  source: string;
  chartType: 'bar' | 'line' | 'area' | 'pie';
  series: { key: string; label: string; color: 1 | 2 | 3 | 4 | 5 }[];
  xField?: string;
  emptyText?: string;
  params?: SourceParam[];
}

/**
 * WC-532 A7: the master control. A dropdown populated from an owned collection
 * `source`; its chosen `valueField` value is published under `name` into the
 * shared master-detail context and consumed by sibling data-bound blocks'
 * `params`. Not a form input.
 */
export interface SelectorBlock {
  type: 'selector';
  name: string;
  label: string;
  source: string;
  valueField: string;
  labelField: string;
  placeholder?: string;
}

// ---- WC-block-modal-drawer: overlay containers ----

/**
 * Container (→ Dialog): overlay content, typically a `form`. Opened by its own
 * `trigger` button (when present) or by a dataTable `open` row action targeting
 * its `id`. `id` carries no dot so `{id}.{field}` addressing is unambiguous.
 */
export interface ModalBlock {
  type: 'modal';
  id: string;
  title: string;
  trigger?: string;
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
  size?: 'sm' | 'md' | 'lg';
  children: Block[];
}

/** Container (→ Sheet): a slide-out panel; same open model as {@link ModalBlock}. */
export interface DrawerBlock {
  type: 'drawer';
  id: string;
  title: string;
  trigger?: string;
  side?: 'left' | 'right';
  children: Block[];
}

/**
 * The discriminated union of every SP1 + SP2 + SP3 + SP4 block, keyed on `type`. The host has
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
  | CodeBlock
  | MathBlock
  | MarkdownBlock
  | DataTableBlock
  | DataStatBlock
  | DataListBlock
  | FormBlock
  | FieldArrayBlock
  | TextInputBlock
  | TextAreaBlock
  | RichTextInputBlock
  | NumberInputBlock
  | SelectBlock
  | CheckboxBlock
  | SliderBlock
  | DateInputBlock
  | FileInputBlock
  | ColorInputBlock
  | BilingualTextInputBlock
  | ReferenceSelectBlock
  | SubmitButtonBlock
  | ActionButtonBlock
  | ChartBlock
  | SelectorBlock
  | ModalBlock
  | DrawerBlock;

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
   * "embed" iframes the plugin's own declared route; "custom" expects a
   * registered override.
   */
  screen: 'crud' | 'custom' | 'action' | 'blocks' | 'embed';
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
      /**
       * "text" | "textarea" | "file". When ANY field in the form is "file",
       * the whole form submits as real multipart/form-data (the file as a
       * genuine binary part) instead of JSON.
       */
      kind: 'text' | 'textarea' | 'file';
      /** Accept filter for file inputs (e.g. ".csv"), or null. */
      accept: string | null;
      /** Whether the field is required. */
      required: boolean;
    }[];
  } | null;
  /** GET route backing an "embed" screen; null for other screens. */
  embed: {
    /** The plugin's own route the host iframes, e.g. "/api/v1/bom/tool". */
    path: string;
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
  // Bounded for the same reason as NavigationProvider's fetch (see
  // navigation-context.tsx): this provider also wraps the whole
  // authenticated app, so an unbounded hang here blocks every admin page. A
  // plain setTimeout+abort rather than AbortSignal.timeout(), which is
  // unsupported in the jsdom test environment this is exercised under.
  const controller = new AbortController();
  const hangGuard = setTimeout(() => controller.abort(), 15_000);
  try {
    const response = await apiClient('/api/v1/frontend/features', {
      signal: controller.signal,
    });
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
  } finally {
    clearTimeout(hangGuard);
  }
}
