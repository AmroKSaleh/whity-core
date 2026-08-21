/**
 * The SDK's declare-once block contract (`sdk/src/Frontend/Blocks/BlockContract.php`),
 * mirrored as a TypeScript discriminated union — the desktop-local twin of
 * `web/lib/plugin-features.ts` (kept desktop-local for this pass, matching
 * that the web copy isn't shared into a package either). The host has
 * already validated the tree (`php-host/sdk/src/Frontend/Blocks/BlockValidator.php`
 * on the desktop side; `BlockValidator.php` server-side), but the renderer
 * revalidates defensively so a malformed node degrades to a placeholder
 * rather than crashing — see `block-renderer.tsx`.
 */

export interface VisibleWhen {
  field: string
  equals?: string | number | boolean
  in?: (string | number | boolean)[]
}

export interface SectionBlock {
  type: "section"
  title?: string
  visibleWhen?: VisibleWhen
  children: Block[]
}

export interface CardBlock {
  type: "card"
  title?: string
  description?: string
  visibleWhen?: VisibleWhen
  children: Block[]
}

export interface GridBlock {
  type: "grid"
  columns: 1 | 2 | 3 | 4
  children: Block[]
}

export interface RowBlock {
  type: "row"
  align?: "start" | "center" | "end" | "between"
  children: Block[]
}

export interface TabsBlock {
  type: "tabs"
  children: TabBlock[]
}

export interface TabBlock {
  type: "tab"
  label: string
  children: Block[]
}

export interface DividerBlock {
  type: "divider"
}

/** #883: `textFrom` binds the heading to a field of a record in the
 * master-detail context, so a record page can title itself with its record's
 * own name. The literal `text` stays REQUIRED and is the fallback — a page
 * whose record has not arrived yet still has a heading. */
export interface HeadingBlock {
  type: "heading"
  level: 1 | 2 | 3 | 4
  text: string
  textFrom?: string
}

export interface TextBlock {
  type: "text"
  value: string
  /** #883: binds the paragraph to a record field; `value` is the fallback. */
  valueFrom?: string
  tone?: "default" | "muted"
}

export interface AlertBlock {
  type: "alert"
  variant: "info" | "success" | "warning" | "danger"
  title?: string
  body: string
}

export interface BadgeBlock {
  type: "badge"
  variant: "neutral" | "info" | "success" | "warning" | "danger"
  label: string
  /** #883: binds the pill to a record field; `label` is the fallback. */
  labelFrom?: string
}

export interface StatBlock {
  type: "stat"
  label: string
  value: string
  /** #883: binds the metric to a record field; `value` is the fallback. */
  valueFrom?: string
  hint?: string
  /** #883: binds the hint to a record field; `hint` is the fallback. */
  hintFrom?: string
  trend?: "up" | "down" | "flat"
}

export interface KeyValueBlock {
  type: "keyValue"
  items: { label: string; value: string }[]
}

export interface ListBlock {
  type: "list"
  ordered?: boolean
  items: string[]
}

export interface TableBlock {
  type: "table"
  columns: { key: string; label: string }[]
  rows: Record<string, string>[]
}

export interface ButtonBlock {
  type: "button"
  label: string
  href: string
  variant?: "primary" | "secondary" | "outline" | "ghost" | "destructive"
}

export interface IconBlock {
  type: "icon"
  name: string
  tone?: "default" | "muted"
}

export interface CodeBlock {
  type: "code"
  language?: string
  content: string
}

export interface MathBlock {
  type: "math"
  expression: string
  block?: boolean
}

export interface MarkdownBlock {
  type: "markdown"
  content: string
}

export type RowAction =
  | { label: string; href: string }
  | { label: string; method: "POST" | "PUT" | "DELETE"; endpoint: string; confirm?: string }
  | { label: string; open: string }

export interface SourceParam {
  param: string
  from: string
}

export interface DataTableBlock {
  type: "dataTable"
  source: string
  columns: { key: string; label: string; sortable?: boolean; filterable?: boolean }[]
  pageSize?: number
  emptyText?: string
  rowActions?: RowAction[]
  params?: SourceParam[]
}

export interface DataStatBlock {
  type: "dataStat"
  source: string
  label: string
  valueField: string
  hintField?: string
  trendField?: string
  emptyText?: string
  params?: SourceParam[]
}

export interface DataListBlock {
  type: "dataList"
  source: string
  itemField: string
  ordered?: boolean
  sortable?: boolean
  filterable?: boolean
  pageSize?: number
  emptyText?: string
  params?: SourceParam[]
}

/**
 * Workflow blocks (#868). Mirrors the SDK contract exactly — see
 * `sdk/src/Frontend/Blocks/BlockContract.php` and `web/lib/plugin-features.ts`.
 */
export interface TimelineBlock {
  type: "timeline"
  source: string
  actorField: string
  actionField: string
  timestampField: string
  noteField?: string
  fromField?: string
  toField?: string
  pageSize?: number
  emptyText?: string
  params?: SourceParam[]
}

/**
 * One candidate action on an inbox item.
 *
 * There is deliberately NO prop for the permission `endpoint` is gated on: the
 * host reads that off the route the endpoint dispatches to, so what the user is
 * shown cannot disagree with what the RBAC gate enforces.
 *
 * `scopedPermission` is the per-record predicate a plugin handler applies inside
 * the request — an ADDITIONAL conjunct that can only hide an action, never
 * reveal one.
 */
export interface ItemAction {
  key: string
  label: string
  method: "POST" | "PUT" | "PATCH" | "DELETE"
  endpoint: string
  scopedPermission?: string
  confirm?: string
  variant?: "primary" | "secondary" | "outline" | "ghost" | "destructive"
}

export interface InboxBlock {
  type: "inbox"
  source: string
  idField: string
  titleField: string
  subtitleField?: string
  timestampField?: string
  statusField?: string
  resourceType?: string
  actions: ItemAction[]
  pageSize?: number
  emptyText?: string
  params?: SourceParam[]
}

/**
 * `submit.endpoint` may carry `{targetId.field}`/`{selector}` context tokens —
 * the same addressing as `params.from`/`defaultFrom` — interpolated from the
 * master-detail context at submit time (e.g. an edit modal PATCHing
 * `/api/persons/{edit-person.id}` for the opened row). See `FormRenderer`.
 */
export interface FormBlock {
  type: "form"
  submit: { method: "POST" | "PUT" | "PATCH"; endpoint: string }
  dataSource?: { method: "GET"; path: string }
  requiredPermission?: string
  children: Block[]
}

export interface FieldArrayBlock {
  type: "fieldArray"
  name: string
  label: string
  itemLabel?: string
  min?: number
  max?: number
  children: Block[]
}

/**
 * `defaultFrom` (WC-block-modal-drawer, additive — never overloads `default`'s
 * literal-value type) is on every one of the SDK's INPUT_LEAF_TYPES: a
 * dot-path address (`"{targetId}.{field}"`, the same addressing
 * `SourceParam.from` uses) into the master-detail context's published row —
 * see `block-renderer.tsx`'s `useEffectiveSource`/`FormInput`. Resolved
 * BEFORE `default` when both are somehow present.
 */
export interface TextInputBlock {
  type: "textInput"
  name: string
  label: string
  placeholder?: string
  required?: boolean
  default?: string
  defaultFrom?: string
  sensitive?: boolean
  visibleWhen?: VisibleWhen
}

export interface TextAreaBlock {
  type: "textArea"
  name: string
  label: string
  rows?: number
  required?: boolean
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface RichTextInputBlock {
  type: "richTextInput"
  name: string
  label: string
  rows?: number
  required?: boolean
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface NumberInputBlock {
  type: "numberInput"
  name: string
  label: string
  min?: number
  max?: number
  step?: number
  required?: boolean
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface SelectBlock {
  type: "select"
  name: string
  label: string
  options: { value: string; label: string }[]
  required?: boolean
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface CheckboxBlock {
  type: "checkbox"
  name: string
  label: string
  default?: boolean
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface SliderBlock {
  type: "slider"
  name: string
  label: string
  min: number
  max: number
  step?: number
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface DateInputBlock {
  type: "dateInput"
  name: string
  label: string
  required?: boolean
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface FileInputBlock {
  type: "fileInput"
  name: string
  label: string
  accept?: string
  required?: boolean
  encoding?: "base64"
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface ColorInputBlock {
  type: "colorInput"
  name: string
  label: string
  default?: string
  defaultFrom?: string
  visibleWhen?: VisibleWhen
}

export interface LocalizedTextValue {
  ar?: string
  en?: string
  [key: string]: string | undefined
}

export interface BilingualTextInputBlock {
  type: "bilingualText"
  name: string
  label: string
  required?: boolean
  arLabel?: string
  enLabel?: string
  defaultFrom?: string
}

export interface ReferenceSelectBlock {
  type: "referenceSelect"
  name: string
  label: string
  source: string
  valueField: string
  labelField: string
  required?: boolean
  placeholder?: string
  default?: string
  defaultFrom?: string
}

/**
 * The three scope kinds an {@link OuScopeValue} may carry — the SDK's
 * `BlockValidator::OU_SCOPES`, same strings, same canonical order. Must stay
 * identical to `web/lib/plugin-features.ts`: a rule written on one host is read
 * by the same consumer as one written on the other.
 */
export type OuScopeKind = "unit" | "subtree" | "children"

/** The canonical scope order, and the default `scopes` when a block declares none. */
export const OU_SCOPE_KINDS: readonly OuScopeKind[] = ["unit", "subtree", "children"]

/**
 * The value an `ouScopePicker` submits (#868): a RULE over the organizational-
 * unit tree, resolved at execution time, never a pinned list of ids.
 *
 * | `unit` | `scope`    | resolves to                                 |
 * |--------|------------|---------------------------------------------|
 * | id     | `unit`     | exactly that unit                           |
 * | id     | `children` | its direct children (`?parent_id=<id>`)     |
 * | id     | `subtree`  | it **and** every descendant (inclusive)     |
 * | `null` | `children` | the root units (`?parent_id=0`)             |
 * | `null` | `subtree`  | every unit in the tenant                    |
 * | `null` | `unit`     | never produced — the nothing-selected state |
 *
 * `scope` is ALWAYS present: "this unit" and "this unit's subtree" are different
 * answers and nothing else in the object distinguishes them. `type`, when
 * non-null, narrows whatever the scope produced to units of that kind.
 */
export interface OuScopeValue {
  unit: number | null
  scope: OuScopeKind
  type: string | null
}

/**
 * Leaf (form only): choose a scope over the organizational-unit tree.
 *
 * Carries no `source`, deliberately — the units and the type vocabulary come
 * from the HOST's own OU endpoints under the caller's own `ous:read` gate, so a
 * plugin has no prop with which to point this control anywhere else.
 */
export interface OuScopePickerBlock {
  type: "ouScopePicker"
  name: string
  label: string
  /** Permitted scopes, in offer order; the first is the opening state. Defaults to all three. */
  scopes?: OuScopeKind[]
  /** Restricts which units may ANCHOR the rule, by kind (`?type=` on the unit fetch). */
  anchorType?: string
  /** Pins the value's `type` to one kind and hides the kind control. */
  memberType?: string
  /** Removes the tenant-wide option, so the rule must be anchored at a unit. */
  required?: boolean
  placeholder?: string
}

export interface SubmitButtonBlock {
  type: "submitButton"
  label: string
  requiredPermission?: string
  variant?: "primary" | "secondary" | "outline" | "ghost" | "destructive"
}

export interface ActionButtonBlock {
  type: "actionButton"
  label: string
  action: { method: "POST" | "PUT" | "PATCH"; endpoint: string }
  requiredPermission?: string
  confirm?: string
  variant?: "primary" | "secondary" | "outline" | "ghost" | "destructive"
}

export interface ChartBlock {
  type: "chart"
  source: string
  chartType: "bar" | "line" | "area" | "pie"
  series: { key: string; label: string; color: 1 | 2 | 3 | 4 | 5 }[]
  xField?: string
  emptyText?: string
  params?: SourceParam[]
}

export interface SelectorBlock {
  type: "selector"
  name: string
  label: string
  source: string
  valueField: string
  labelField: string
  placeholder?: string
}

/**
 * Container (WC-block-modal-drawer): `id` is a blockId (non-empty, no dots,
 * no whitespace — the SDK validator enforces this, unambiguous for
 * `{id}.{field}` addressing). `trigger`, when present, is a plain button
 * label rendered internally (NOT a nested Block — there is no trigger
 * slot); absent means the overlay is only opened via a `dataTable`
 * rowAction's `open: id`. One homogeneous `children` slot, same as every
 * other container.
 */
export interface ModalBlock {
  type: "modal"
  id: string
  title: string
  trigger?: string
  variant?: "primary" | "secondary" | "outline" | "ghost" | "destructive"
  size?: "sm" | "md" | "lg"
  children: Block[]
}

export interface DrawerBlock {
  type: "drawer"
  id: string
  title: string
  trigger?: string
  side?: "left" | "right"
  children: Block[]
}

/** #883: one FACT a record states about itself — the field to read, and the
 * label to read it under. Labels live here rather than on `recordFields`
 * because a record page shows the same field in more than one place, and a
 * label restated per placement drifts per placement. */
export interface RecordFact {
  field: string
  label: string
}

/**
 * Container (#883): fetches ONE resource and publishes it into the
 * master-detail context under `id`, where every block reads it through the same
 * `{id}.{field}` addressing an `open` row action already uses.
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
  type: "dataRecord"
  id: string
  source: string
  fields: RecordFact[]
  emptyText?: string
  params?: SourceParam[]
  children: Block[]
}

/** Leaf (#883): the data-bound `keyValue` — a description list of the facts a
 * `dataRecord` published under `from`. `fields` picks a subset, in the order
 * given; omitted, every declared fact is rendered. */
export interface RecordFieldsBlock {
  type: "recordFields"
  from: string
  fields?: string[]
  emptyText?: string
}

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
  | OuScopePickerBlock
  | SubmitButtonBlock
  | ActionButtonBlock
  | ChartBlock
  | SelectorBlock
  | TimelineBlock
  | InboxBlock
  | ModalBlock
  | DrawerBlock
  | DataRecordBlock
  | RecordFieldsBlock

/** A single plugin-contributed UI feature, as published by the offline host's
 * `GET /__whity/frontend-features` (mirrors the server's `GET /api/v1/frontend/features`). */
export interface PluginFeature {
  id: string
  plugin: string
  label: string
  icon: string | null
  group: string
  order: number
  screen: "crud" | "custom" | "action" | "blocks" | "embed"
  requiredPermission: string
  blocks?: Block[]
}
