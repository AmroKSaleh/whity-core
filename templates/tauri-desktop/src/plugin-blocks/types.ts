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

export interface HeadingBlock {
  type: "heading"
  level: 1 | 2 | 3 | 4
  text: string
}

export interface TextBlock {
  type: "text"
  value: string
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
}

export interface StatBlock {
  type: "stat"
  label: string
  value: string
  hint?: string
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
  | DrawerBlock

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
