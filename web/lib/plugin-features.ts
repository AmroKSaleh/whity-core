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
 * A presentational conditional-visibility predicate (WC-532 A3, widened by
 * #909). Render-time only: it never affects the submitted payload or
 * server-side validation, and the server never trusts it.
 *
 * The rule names exactly ONE subject and then how to test it. The SDK validator
 * enforces that; this type keeps all three optional because the renderer
 * revalidates a payload it did not build.
 *
 *   - `field`  a sibling input in the same `form` — WC-532 A3's only subject.
 *   - `from`   a master-detail context reference (`{recordId}.{field}`, or a
 *              bare `selector` name): the RECORD the page is about.
 *   - `access` an {@link AccessGateBlock} id — the HOST's answer to "may this
 *              caller make that request?". Takes a boolean `equals`, never `in`.
 *
 * Facts fail OPEN and authority fails CLOSED, which is the one asymmetry worth
 * knowing: a `field`/`from` reference that does not resolve leaves the block
 * visible (content is never permanently hidden by a missing context), while an
 * `access` answer that has not arrived, or cannot be had, hides it — a control
 * shown before its permission is known is a control shown to somebody who may
 * not have it.
 */
export interface VisibleWhen {
  field?: string;
  from?: string;
  access?: string;
  equals?: string | number | boolean;
  in?: (string | number | boolean)[];
}

/**
 * The facets EVERY block carries, whatever its type
 * (`BlockContract::UNIVERSAL_PROPS`).
 *
 * Intersected into {@link Block} rather than repeated on each interface, for
 * the same reason the SDK merges it into every rule: a condition only some
 * blocks may carry is a condition granular gating has to route around.
 */
export interface BlockFacets {
  visibleWhen?: VisibleWhen;
}

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
  /**
   * Intersected with the universal facet because a `tab` renders from inside
   * `TabsRenderer` rather than through `BlockNode`, so its `visibleWhen` is read
   * there directly (#909).
   */
  children: (TabBlock & BlockFacets)[];
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

/**
 * Leaf: a semantic heading at one of four levels.
 *
 * #883: `textFrom` binds the heading to a field of a record in the
 * master-detail context, so a record page can title itself with the record's
 * own name. The literal `text` stays REQUIRED and is the fallback — a page
 * whose record has not arrived yet still has a heading.
 */
export interface HeadingBlock {
  type: 'heading';
  level: 1 | 2 | 3 | 4;
  text: string;
  textFrom?: string;
}

/** Leaf: a paragraph of text, optionally muted. `valueFrom` binds it to a record field (#883). */
export interface TextBlock {
  type: 'text';
  value: string;
  valueFrom?: string;
  tone?: 'default' | 'muted';
}

/** Leaf: a callout banner with a semantic variant. */
export interface AlertBlock {
  type: 'alert';
  variant: 'info' | 'success' | 'warning' | 'danger';
  title?: string;
  body: string;
}

/** Leaf: a small status pill. `labelFrom` binds it to a record field (#883). */
export interface BadgeBlock {
  type: 'badge';
  variant: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
  label: string;
  labelFrom?: string;
}

/** Leaf: a single metric tile with an optional hint and trend. `valueFrom`/`hintFrom` bind to record fields (#883). */
export interface StatBlock {
  type: 'stat';
  label: string;
  value: string;
  valueFrom?: string;
  hint?: string;
  hintFrom?: string;
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

// ---- workflow blocks (#868) ----

/**
 * An ordered, append-only EVENT LIST — the audit-trail shape. Data-bound like
 * `dataStat`: one ownership-checked `source`, then per-field mappings. Declares
 * no endpoint and no verb, so read-only is a property of the type.
 */
export interface TimelineBlock {
  type: 'timeline';
  source: string;
  actorField: string;
  actionField: string;
  timestampField: string;
  noteField?: string;
  fromField?: string;
  toField?: string;
  pageSize?: number;
  emptyText?: string;
  params?: SourceParam[];
}

/**
 * One candidate action on an inbox item.
 *
 * There is deliberately NO prop for the permission `endpoint` is gated on: the
 * host reads that off the route the endpoint dispatches to, so what the user is
 * shown cannot disagree with what the middleware enforces.
 *
 * `scopedPermission` is a different question — the per-record predicate a
 * plugin's handler applies inside the request, which no route table can express.
 * It is resolved at (`InboxBlock.resourceType`, the item's id) and is an
 * ADDITIONAL conjunct, so it can only ever hide an action, never reveal one.
 */
export interface ItemAction {
  key: string;
  label: string;
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  endpoint: string;
  scopedPermission?: string;
  confirm?: string;
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
}

/**
 * The most nodes a `flow` may draw, mirroring
 * `BlockContract::FLOW_MAX_NODES` (#950, inheriting #192).
 *
 * A readability ceiling, not a payload one: a canvas of several hundred boxes
 * has stopped being a diagram, and every renderer on this library inherits that
 * as soon as a tenant's data grows. The host validator already refuses a
 * `maxNodes` above it, so this copy is the RENDERER's own floor — it is what
 * decides how many nodes reach the canvas when a payload arrives larger than
 * anyone declared, which no amount of contract validation can prevent.
 */
export const FLOW_MAX_NODES = 150;

/**
 * A GRAPH: a set of nodes and the edges between them — the shape no other block
 * can express.
 *
 * Data-bound like `dataTable`: ONE ownership-checked `source` returning a
 * collection, and a row IS a node. Edges are references off the node rows to
 * other nodes' ids — `edgeFromField` a predecessor pointer, `edgeToField` a
 * successor pointer, either optionally holding a LIST so a step can branch. With
 * neither declared the nodes are a linear sequence in payload order, which is
 * the common case (an ordered list of steps) and costs the plugin nothing.
 *
 * Read-only by construction: no endpoint, no verb. Editing is a form opened from
 * a node, declared through `nodeActions` — the same `RowAction` shape
 * `dataTable.rowActions` uses, rendered by the same code path.
 */
export interface FlowBlock {
  type: 'flow';
  source: string;
  nodeIdField: string;
  nodeLabelField: string;
  nodeSubtitleField?: string;
  edgeFromField?: string;
  edgeToField?: string;
  orientation?: 'horizontal' | 'vertical';
  nodeActions?: RowAction[];
  /** Lowers {@link FLOW_MAX_NODES} for this graph; the contract refuses a higher value. */
  maxNodes?: number;
  emptyText?: string;
  params?: SourceParam[];
}

/**
 * A TASK LIST: the items awaiting the current user, each carrying the actions
 * that user may actually take on it.
 *
 * The plugin supplies the items (`source`); core resolves which actions are
 * permitted, per item, via `POST /api/v1/me/permitted-actions`.
 */
export interface InboxBlock {
  type: 'inbox';
  source: string;
  idField: string;
  titleField: string;
  subtitleField?: string;
  timestampField?: string;
  statusField?: string;
  resourceType?: string;
  actions: ItemAction[];
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
  submit: { method: 'POST' | 'PUT' | 'PATCH'; endpoint: string };
  /** If present, the form GETs this path on mount and pre-populates fields with the response. */
  dataSource?: { method: 'GET'; path: string };
  requiredPermission?: string;
  children: Block[];
}

/**
 * WC-532 A2: a repeatable field-group (form only). `children` is the per-row
 * sub-form template; the renderer collects the rows into a JSON array submitted
 * under `name`. `min`/`max` bound the row count; `itemLabel` names each row.
 *
 * `source` (optional) turns it from a composer into an EDITOR: the rows are
 * seeded once from that path and the submit replaces the stored set. See the
 * `fieldArray` entry in the SDK `BlockContract` for why that makes an empty
 * render a destructive act, and `FieldArrayRenderer` for the gate that stops it.
 */
export interface FieldArrayBlock {
  type: 'fieldArray';
  name: string;
  label: string;
  itemLabel?: string;
  min?: number;
  max?: number;
  /** If present, rows are seeded from this path instead of starting empty. */
  source?: string;
  /** Master-detail bindings for `source`; ALL must resolve before it fetches. */
  params?: SourceParam[];
  children: Block[];
}

/**
 * WC-532 item 3: a form region whose shape depends on another field's value.
 *
 * Only the case matching `discriminator`'s current value renders, and only its
 * inputs reach the submit payload — the deliberate exception to the rule that a
 * hidden input still submits. See `inactiveVariantInputNames` in form-context.
 */
export interface VariantBlock {
  type: 'variant';
  /** The name of a sibling input in the same form whose value selects a case. */
  discriminator: string;
  children: Block[];
}

export interface VariantCaseBlock {
  type: 'variantCase';
  /** The discriminator value this branch answers to. Compared as a string. */
  when: string;
  label?: string;
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
}

/** Leaf (form only): a boolean checkbox. */
export interface CheckboxBlock {
  type: 'checkbox';
  name: string;
  label: string;
  default?: boolean;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
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
}

/** Leaf (form only): a colour picker. */
export interface ColorInputBlock {
  type: 'colorInput';
  name: string;
  label: string;
  default?: string;
  /** WC-block-modal-drawer: a dot-path (`{targetId}.{field}`) or bare selector name into the master-detail context; resolved before `default`. */
  defaultFrom?: string;
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

// ---- organizational-unit scope picker (#868) ----

/**
 * The three scope kinds an {@link OuScopeValue} may carry — the SDK's
 * `BlockValidator::OU_SCOPES`, in the same canonical order.
 */
export type OuScopeKind = 'unit' | 'subtree' | 'children';

/** The canonical scope order, and the default `scopes` when a block declares none. */
export const OU_SCOPE_KINDS: readonly OuScopeKind[] = ['unit', 'subtree', 'children'];

/**
 * The value an `ouScopePicker` submits: a RULE over the organizational-unit
 * tree, resolved at execution time, never a pinned list of ids.
 *
 *     { unit: 42, scope: 'subtree', type: 'department' }
 *       → that faculty and every unit beneath it, narrowed to departments
 *
 * `scope` is ALWAYS present. "This unit" and "this unit's subtree" are different
 * answers, and nothing about the other two fields lets a reader tell them apart,
 * so the discriminator is written every time rather than inferred.
 *
 * | `unit` | `scope`    | resolves to                                  |
 * |--------|------------|----------------------------------------------|
 * | id     | `unit`     | exactly that unit                            |
 * | id     | `children` | its direct children (`?parent_id=<id>`)      |
 * | id     | `subtree`  | it **and** every descendant (inclusive)      |
 * | `null` | `children` | the root units (`?parent_id=0`)              |
 * | `null` | `subtree`  | every unit in the tenant                     |
 * | `null` | `unit`     | never produced — the nothing-selected state  |
 *
 * `type`, when non-null, filters whatever that produced to units of that kind
 * (`?type=<key>`), applied AFTER the scope expands and never instead of it.
 */
export interface OuScopeValue {
  /** Anchor unit id, or null for the whole tenant. */
  unit: number | null;
  /** Which units around the anchor the rule covers. Never omitted. */
  scope: OuScopeKind;
  /** OU type key (#822) narrowing the resolved set, or null for any kind. */
  type: string | null;
}

/**
 * Whether a form value is an OU scope rule. The discriminator is `scope`, which
 * every rule carries — the same field a consumer switches on when it resolves
 * one, so there is exactly one thing to look at in both places.
 */
export function isOuScopeValue(value: unknown): value is OuScopeValue {
  return (
    typeof value === 'object' &&
    value !== null &&
    !Array.isArray(value) &&
    typeof (value as OuScopeValue).scope === 'string' &&
    (OU_SCOPE_KINDS as readonly string[]).includes((value as OuScopeValue).scope)
  );
}

/**
 * Leaf (form only): choose a SCOPE over the organizational-unit tree.
 *
 * Deliberately carries no `source`. The units and the type vocabulary come from
 * core's own `GET /api/v1/ous` and `GET /api/v1/ou-types`, under the caller's own
 * `ous:read` gate — a plugin has no prop with which to point this control
 * anywhere else, and could not name core's routes through the loader's
 * `source`-ownership check even if it had one.
 */
export interface OuScopePickerBlock {
  type: 'ouScopePicker';
  name: string;
  label: string;
  /** Permitted scopes, in offer order; the first is the opening state. Defaults to all three. */
  scopes?: OuScopeKind[];
  /** Restricts which units may ANCHOR the rule, by kind (`?type=` on the unit fetch). */
  anchorType?: string;
  /** Pins the value's `type` to one kind and hides the kind control. */
  memberType?: string;
  /** Removes the tenant-wide option, so the rule must be anchored at a unit. */
  required?: boolean;
  placeholder?: string;
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
  action: { method: 'POST' | 'PUT' | 'PATCH'; endpoint: string };
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

/**
 * #883: one FACT a record states about itself — the field to read and the label
 * to read it under. The labels live here rather than on `recordFields` because a
 * record page shows the same field in more than one place, and a label restated
 * per placement drifts per placement.
 */
export interface RecordFact {
  field: string;
  label: string;
}

/**
 * Container (#883): fetches ONE resource and publishes it into the
 * master-detail context under `id`, where every block in the tree reads it
 * through the same `{id}.{field}` addressing an `open` row action already uses.
 *
 * ONLY the fields named in `fields` are published. That is the structural half
 * of the #895 guard: a caller-permission flag riding along in the payload is
 * unreachable from the tree because it was never published, whatever it is
 * called. The named-vocabulary half lives in the SDK's `BlockValidator`.
 *
 * `source` may carry `{token}` segments in the master-detail addressing — a
 * selector's value, a row an overlay was opened with, or `{record}`, the record
 * a record-page route is about. The block does not fetch until every token
 * resolves.
 */
export interface DataRecordBlock {
  type: 'dataRecord';
  id: string;
  source: string;
  fields: RecordFact[];
  emptyText?: string;
  params?: SourceParam[];
  children: Block[];
}

/**
 * Leaf (#883): the data-bound `keyValue` — a description list of the facts a
 * `dataRecord` published under `from`. `fields` picks a subset, in the order
 * given; omitted, every declared fact is rendered.
 */
export interface RecordFieldsBlock {
  type: 'recordFields';
  from: string;
  fields?: string[];
  emptyText?: string;
}

/**
 * The one concrete request an {@link AccessGateBlock} asks the host about. Its
 * `endpoint` may carry `{token}` segments in the master-detail addressing, like
 * a `dataRecord`'s source; the gate is not asked until every token resolves.
 */
export interface AccessCheck {
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  endpoint: string;
}

/**
 * Container (#909): the CALLER-ACCESS primitive.
 *
 * Declares one question about the caller, publishes the host's answer under
 * `id`, and renders `children` when the answer is yes and `otherwise` when it is
 * no. Both are optional: a gate with neither is purely a declaration that
 * `visibleWhen: {access: id}` reads from anywhere on the screen.
 *
 * The answer comes from `POST /api/v1/me/permitted-actions` — the same route
 * lookup feeding the same RoleChecker calls RbacMiddleware makes — so a plugin
 * never states which permission gates the region and there is no second copy of
 * that answer to drift.
 *
 * The two slots exist so the editable and read-only renderings of a region are
 * declared TOGETHER. Written as two siblings with opposite `visibleWhen`
 * polarity they can drift, and when they drift it is the editable half that
 * ends up showing.
 */
export interface AccessGateBlock {
  type: 'accessGate';
  id: string;
  check: AccessCheck;
  children?: Block[];
  otherwise?: Block[];
}

/**
 * Leaf (standalone): an ISSUED DOCUMENT (#947 item 4).
 *
 * Deliberately carries no `source`, for the same reason {@link OuScopePickerBlock}
 * does and with more at stake. Every `source`/`recordPath` in the contract is
 * ownership-checked by the host loader against the declaring plugin's own
 * routes, so core's `/api/v1/documents/{id}` cannot be named by any plugin. The
 * only composition that would work is a plugin republishing core's document
 * reads through a route of its own — a second read path onto an auditable
 * record, gated however that plugin chose. So the host does the fetching, on
 * core's endpoints, under the caller's session and the `documents:read` gate
 * those routes already carry.
 */
export interface DocumentViewerBlock {
  type: 'documentViewer';
  /**
   * Where the document id comes from: a bare selector name or a dotted
   * `{blockId}.{field}` into the master-detail context. There is no literal
   * twin — an unresolved binding renders `emptyText`, never some other
   * document. See the SDK contract for why that inverts the `heading.text` rule.
   */
  documentIdFrom: string;
  /**
   * PINS one artifact, addressed the same way. Declared, the viewer shows that
   * artifact and refuses to substitute another; absent, it shows the current
   * one and says so.
   */
  artifactIdFrom?: string;
  /** What to say while nothing has named a document. */
  emptyText?: string;
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
export type Block = BlockFacets &
  ( | SectionBlock
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
  | VariantBlock
  | VariantCaseBlock
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
  | OuScopePickerBlock
  | DocumentViewerBlock
  | SubmitButtonBlock
  | ActionButtonBlock
  | ChartBlock
  | SelectorBlock
  | TimelineBlock
  | InboxBlock
  | FlowBlock
  | ModalBlock
  | DrawerBlock
  | DataRecordBlock
  | RecordFieldsBlock
  | AccessGateBlock );

/**
 * Why one write capability came back false (issue #951).
 *
 * A capability is false for three unrelated reasons — nothing is registered to
 * satisfy the action, the plugin registered the wrong method, or the caller
 * lacks the RBAC — and the renderer used to answer all three by omitting the
 * control, so a correct screen the viewer has no rights on looked exactly like
 * a broken one. The control is now disabled and carries this instead.
 */
export interface CapabilityDenial {
  /**
   * The stable machine discriminant, used to key a localized string:
   *   - `no-resource` the feature declares no resource at all;
   *   - `no-route`    a resource exists but no route satisfies this action;
   *   - `forbidden`   the route exists and the caller's RBAC denies it.
   */
  code: 'no-resource' | 'no-route' | 'forbidden';
  /**
   * The audience-safe explanation, shown to whoever is looking at the screen.
   * Never names an internal identifier; doubles as the i18n fallback.
   */
  reason: string;
  /**
   * The operator-grade half: the exact route the platform looked for, or the
   * exact RBAC the matched route demands. Non-null ONLY for a caller holding
   * `plugins:read` — the server decides, the client just renders what it got.
   */
  detail: string | null;
}

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
  /**
   * Why each FALSE capability is false (issue #951). One entry per denied
   * capability — a granted one has none — so the object is empty when all
   * three are held. Optional because a pre-#951 backend does not send it.
   */
  capabilityReasons?: Partial<
    Record<'canCreate' | 'canEdit' | 'canDelete', CapabilityDenial>
  >;
}

/**
 * A feature descriptor the host REFUSED, and why (issue #953).
 *
 * A rejected feature used to just not be in the navigation — indistinguishable
 * from a permission problem, a caching problem, or a typo in the screen id, and
 * findable only by reading container logs. The rules that reject are correct;
 * the answer was in the wrong place.
 *
 * Served only to a caller holding `plugins:read`, because the reasons quote
 * route paths and permission names.
 */
export interface DroppedPluginFeature {
  /** The declaring plugin's name, matching {@link PluginFeature.plugin}. */
  plugin: string;
  /** The declared feature id, or null when the descriptor had no usable one. */
  featureId: string | null;
  /** The host's exact reason for refusing it. */
  reason: string;
}

/** What {@link fetchPluginFeatures} resolves to. */
export interface PluginFeatureList {
  features: PluginFeature[];
  /**
   * Refused descriptors. Empty for a caller who may not read them, which is
   * indistinguishable here from "nothing was refused" — deliberately: only the
   * plugin console renders this, and it is gated on the same permission.
   */
  dropped: DroppedPluginFeature[];
}

/** Narrow an unknown payload to the `{ data: PluginFeature[] }` envelope. */
function isFeatureListResponse(body: unknown): body is { data: PluginFeature[] } {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return false;
  }
  return Array.isArray((body as { data: unknown }).data);
}

/**
 * Read the optional `dropped` array off the envelope (issue #953).
 *
 * Defensive per entry rather than all-or-nothing: this is diagnostic data, and
 * one malformed row must not cost the administrator the other reasons.
 */
function readDropped(body: unknown): DroppedPluginFeature[] {
  if (typeof body !== 'object' || body === null || !('dropped' in body)) {
    return [];
  }
  const raw = (body as { dropped: unknown }).dropped;
  if (!Array.isArray(raw)) {
    return [];
  }

  const dropped: DroppedPluginFeature[] = [];
  for (const entry of raw) {
    if (typeof entry !== 'object' || entry === null) {
      continue;
    }
    const record = entry as Record<string, unknown>;
    if (typeof record.plugin !== 'string' || typeof record.reason !== 'string') {
      continue;
    }
    dropped.push({
      plugin: record.plugin,
      featureId: typeof record.featureId === 'string' ? record.featureId : null,
      reason: record.reason,
    });
  }
  return dropped;
}

/** The all-failures fallback, so every failure branch returns the same shape. */
const EMPTY_FEATURE_LIST: PluginFeatureList = { features: [], dropped: [] };

/**
 * Fetch the permission-filtered feature list for the current user, plus the
 * descriptors the host refused (issue #953, empty unless the caller holds
 * `plugins:read`).
 *
 * Resolves to an empty list on any failure (non-ok status, malformed body,
 * network error) — callers render "no plugin features" rather than crash.
 */
export async function fetchPluginFeatures(): Promise<PluginFeatureList> {
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
      return EMPTY_FEATURE_LIST;
    }
    const body: unknown = await response.json();
    if (!isFeatureListResponse(body)) {
      return EMPTY_FEATURE_LIST;
    }
    return { features: body.data, dropped: readDropped(body) };
  } catch {
    return EMPTY_FEATURE_LIST;
  } finally {
    clearTimeout(hangGuard);
  }
}
