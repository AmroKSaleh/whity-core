/**
 * Desktop renderer for the SDK's declare-once block contract
 * (`sdk/src/Frontend/Blocks/BlockContract.php`) — the desktop twin of
 * `web/components/plugin/blocks/block-renderer.tsx`. Maps each block `type`
 * to an `@amroksaleh/ui` primitive via a switch/registry, exactly like the
 * web version; data-bound blocks fetch/submit through the PHP host proxy
 * (`use-plugin-data.ts`/`submit-plugin-action.ts`) instead of a browser
 * fetch. Never throws: an unknown or malformed node renders
 * `UnsupportedBlock` rather than crashing the whole feature.
 */
import * as React from "react"

import { Alert, AlertDescription, AlertTitle } from "@amroksaleh/ui/alert"
import { Badge } from "@amroksaleh/ui/badge"
import { BilingualInput, type BilingualValue } from "@amroksaleh/ui/bilingual-input"
import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@amroksaleh/ui/card"
import { Chart } from "@amroksaleh/ui/chart"
import { Checkbox } from "@amroksaleh/ui/checkbox"
import { DataTable } from "@amroksaleh/ui/data-table"
import { DatePicker } from "@amroksaleh/ui/date-picker"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@amroksaleh/ui/dialog"
import { EmptyState, ErrorState } from "@amroksaleh/ui/empty-state"
import { Input } from "@amroksaleh/ui/input"
import { Pagination } from "@amroksaleh/ui/pagination"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@amroksaleh/ui/select"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@amroksaleh/ui/sheet"
import { Skeleton } from "@amroksaleh/ui/skeleton"
import { Slider } from "@amroksaleh/ui/slider"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@amroksaleh/ui/table"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@amroksaleh/ui/tabs"
import { Textarea } from "@amroksaleh/ui/textarea"

import { resolveTablerIcon } from "./resolve-tabler-icon"
import { submitPluginAction } from "./submit-plugin-action"
import type {
  Block,
  ChartBlock,
  DataListBlock,
  DataStatBlock,
  DataTableBlock,
  DrawerBlock,
  FieldArrayBlock,
  FormBlock,
  ModalBlock,
  PluginFeature,
  RowAction,
  SelectorBlock,
  SourceParam,
  VisibleWhen,
} from "./types"
import { usePluginData, type PluginDataState } from "./use-plugin-data"

type ButtonVariant = "primary" | "secondary" | "outline" | "ghost" | "destructive"

function toButtonVariant(v?: ButtonVariant): "default" | "secondary" | "outline" | "ghost" | "destructive-solid" {
  switch (v) {
    case "secondary":
      return "secondary"
    case "outline":
      return "outline"
    case "ghost":
      return "ghost"
    case "destructive":
      return "destructive-solid"
    default:
      return "default"
  }
}

function toBadgeVariant(v: "neutral" | "info" | "success" | "warning" | "danger"): "secondary" | "info" | "success" | "warning" | "destructive" {
  return v === "neutral" ? "secondary" : v === "danger" ? "destructive" : v
}

function toAlertVariant(v: "info" | "success" | "warning" | "danger"): "info" | "success" | "warning" | "destructive" {
  return v === "danger" ? "destructive" : v
}

// ---------------------------------------------------------------- master-detail

/**
 * WC-block-modal-drawer: widened, additive to the original selector-driven
 * `selections`/`setSelection` (untouched, still scalar-only) — `rows` is the
 * row a `dataTable` `open` rowAction publishes when it opens a modal/drawer
 * by that overlay's blockId; `openTargets` tracks which overlay ids are
 * currently open. `refreshSignal` increments ONLY when a close follows a
 * successful form submit (`closeTarget(id, {refresh: true})` — a plain
 * dismiss/cancel/backdrop-click close does not refetch anything), matching
 * the web renderer's refresh-nonce contract exactly (confirmed with the
 * backend agent) — every data-bound block in the feature refetches on that
 * signal, not just the one that opened the overlay — see `useRefetchOnSignal`.
 */
interface MasterDetailContextValue {
  selections: Record<string, string>
  setSelection: (name: string, value: string) => void
  rows: Record<string, Record<string, unknown>>
  openTargets: Record<string, boolean>
  openTarget: (id: string, row?: Record<string, unknown>) => void
  /** `refresh: true` (only ever passed by a successful form submit, see
   * `FormRenderer`) is what bumps `refreshSignal` — a plain dismiss/cancel/
   * backdrop-click close does NOT refetch anything, matching the web
   * renderer's "refresh nonce only on submit-success" contract exactly
   * (confirmed with the backend agent; this was a real bug here before —
   * every close used to bump the signal regardless of why). */
  closeTarget: (id: string, options?: { refresh?: boolean }) => void
  refreshSignal: number
}
const MasterDetailContext = React.createContext<MasterDetailContextValue>({
  selections: {},
  setSelection: () => {},
  rows: {},
  openTargets: {},
  openTarget: () => {},
  closeTarget: () => {},
  refreshSignal: 0,
})

function MasterDetailProvider({ children }: { children: React.ReactNode }) {
  const [selections, setSelections] = React.useState<Record<string, string>>({})
  const [rows, setRows] = React.useState<Record<string, Record<string, unknown>>>({})
  const [openTargets, setOpenTargets] = React.useState<Record<string, boolean>>({})
  const [refreshSignal, setRefreshSignal] = React.useState(0)

  const setSelection = React.useCallback((name: string, value: string) => {
    setSelections((prev) => ({ ...prev, [name]: value }))
  }, [])
  const openTarget = React.useCallback((id: string, row?: Record<string, unknown>) => {
    if (row) setRows((prev) => ({ ...prev, [id]: row }))
    setOpenTargets((prev) => ({ ...prev, [id]: true }))
  }, [])
  const closeTarget = React.useCallback((id: string, options?: { refresh?: boolean }) => {
    setOpenTargets((prev) => (prev[id] ? { ...prev, [id]: false } : prev))
    if (options?.refresh) setRefreshSignal((n) => n + 1)
  }, [])

  const value = React.useMemo<MasterDetailContextValue>(
    () => ({ selections, setSelection, rows, openTargets, openTarget, closeTarget, refreshSignal }),
    [selections, setSelection, rows, openTargets, openTarget, closeTarget, refreshSignal],
  )
  return <MasterDetailContext.Provider value={value}>{children}</MasterDetailContext.Provider>
}

/** Resolves a `params`/`defaultFrom` address: a bare name reads the
 * selector-driven `selections` (unchanged behavior); a dotted
 * `{targetId}.{field}` reads the row a modal/drawer's opener published. */
function resolveFromContext(from: string, ctx: Pick<MasterDetailContextValue, "selections" | "rows">): unknown {
  const dot = from.indexOf(".")
  if (dot === -1) return ctx.selections[from]
  return ctx.rows[from.slice(0, dot)]?.[from.slice(dot + 1)]
}

/** `defaultFrom` (resolved via `resolveFromContext`) wins over the literal
 * `default` when both resolve — an edit modal opened with a row always
 * wants the row's current value, not the block's static placeholder. */
function resolveDefault(block: { default?: unknown; defaultFrom?: string }, ctx: Pick<MasterDetailContextValue, "selections" | "rows">): unknown {
  if (block.defaultFrom) {
    const resolved = resolveFromContext(block.defaultFrom, ctx)
    if (resolved !== undefined) return resolved
  }
  return block.default
}

/** Interpolates `{targetId.field}`/`{selector}` context tokens in a form's
 * `submit.endpoint` (same addressing as `params.from`/`defaultFrom`, via
 * `resolveFromContext`) — e.g. an edit modal PATCHing
 * `/api/persons/{edit-person.id}` for the row it was opened with. An
 * unresolved token becomes `''`, matching the web renderer/SDK contract's
 * no-cross-reference stance. */
function interpolateEndpoint(endpoint: string, ctx: Pick<MasterDetailContextValue, "selections" | "rows">): string {
  return endpoint.replace(/\{([^}]+)\}/g, (_match, ref: string) => {
    const resolved = resolveFromContext(ref, ctx)
    return encodeURIComponent(resolved === undefined ? "" : String(resolved))
  })
}

/** A row's published field can arrive as a real boolean or as a string
 * ("true"/"1" vs. "false"/"0") depending on how the source serialized it —
 * plain `Boolean(...)` truthiness is wrong here since "false" and "0" are
 * both non-empty strings. Matches the web renderer's coercion. */
function coerceBoolean(value: unknown): boolean {
  if (typeof value === "boolean") return value
  if (typeof value === "string") return value === "true" || value === "1"
  if (typeof value === "number") return value === 1
  return Boolean(value)
}

/** Applies a data-bound block's `params` (master-detail query params) on top
 * of its `source`, same as the web renderer's `useEffectiveSource`. */
function useEffectiveSource(source: string, params?: SourceParam[]): string {
  const ctx = React.useContext(MasterDetailContext)
  return React.useMemo(() => {
    if (!params || params.length === 0) return source
    const pairs = params
      .map((p) => {
        const value = resolveFromContext(p.from, ctx)
        return value === undefined ? null : `${encodeURIComponent(p.param)}=${encodeURIComponent(String(value))}`
      })
      .filter((p): p is string => p !== null)
    if (pairs.length === 0) return source
    return `${source}${source.includes("?") ? "&" : "?"}${pairs.join("&")}`
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, params, ctx.selections, ctx.rows])
}

/** Refetches a data-bound block's `usePluginData` result whenever a
 * successful form submit closes an overlay anywhere in this feature
 * (skipped on first mount) — every data-bound block refetches, not just
 * ones "belonging" to the overlay that closed, absent a declared dependency
 * graph between a specific overlay and specific blocks (matches the web
 * renderer's refresh-nonce scope). */
function useRefetchOnSignal(state: PluginDataState<unknown>): void {
  const { refreshSignal } = React.useContext(MasterDetailContext)
  const stateRef = React.useRef(state)
  stateRef.current = state
  const mounted = React.useRef(false)
  React.useEffect(() => {
    if (!mounted.current) {
      mounted.current = true
      return
    }
    const current = stateRef.current
    if (current.status === "ready" || current.status === "empty") current.refresh()
    else if (current.status === "error") current.retry()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshSignal])
}

// ---------------------------------------------------------------- form scope

interface FormScope {
  values: Record<string, unknown>
  setValue: (name: string, value: unknown) => void
}
const FormScopeContext = React.createContext<FormScope | null>(null)

/** The enclosing `modal`/`drawer` blockId, or `null` at the top level —
 * lets `FormRenderer` auto-close the overlay it's nested in on a successful
 * submit (a renderer convention; there is no contract prop for it). */
const ModalScopeContext = React.createContext<string | null>(null)

function isVisible(visibleWhen: VisibleWhen | undefined, values: Record<string, unknown>): boolean {
  if (!visibleWhen) return true
  const current = values[visibleWhen.field]
  if (visibleWhen.equals !== undefined) return current === visibleWhen.equals
  if (visibleWhen.in !== undefined) return visibleWhen.in.includes(current as never)
  return true
}

// ---------------------------------------------------------------- public entry

export function BlockRenderer({ feature }: { feature: PluginFeature }) {
  const blocks = feature.blocks
  if (!Array.isArray(blocks)) {
    return <ErrorState title="No content" description="This feature declared no renderable blocks." />
  }
  return (
    <MasterDetailProvider>
      <BlockList blocks={blocks} />
    </MasterDetailProvider>
  )
}

function BlockList({ blocks }: { blocks: Block[] }) {
  return (
    <div className="space-y-4">
      {blocks.map((block, i) => (
        <BlockNode key={i} block={block} />
      ))}
    </div>
  )
}

function UnsupportedBlock({ reason }: { reason: string }) {
  return <p className="text-xs text-muted-foreground italic">Unsupported block: {reason}</p>
}

// ---------------------------------------------------------------- dispatch

function BlockNode({ block }: { block: Block }) {
  const form = React.useContext(FormScopeContext)

  switch (block.type) {
    case "section": {
      if (!isVisible(block.visibleWhen, form?.values ?? {})) return null
      return (
        <div className="space-y-3">
          {block.title && <h3 className="text-sm font-semibold">{block.title}</h3>}
          <BlockList blocks={block.children} />
        </div>
      )
    }
    case "card": {
      if (!isVisible(block.visibleWhen, form?.values ?? {})) return null
      return (
        <Card>
          {(block.title || block.description) && (
            <CardHeader>
              {block.title && <CardTitle className="text-sm">{block.title}</CardTitle>}
              {block.description && <CardDescription>{block.description}</CardDescription>}
            </CardHeader>
          )}
          <CardContent className="space-y-3">
            <BlockList blocks={block.children} />
          </CardContent>
        </Card>
      )
    }
    case "grid":
      return (
        <div className={`grid gap-4 sm:grid-cols-${block.columns}`}>
          {block.children.map((child, i) => (
            <BlockNode key={i} block={child} />
          ))}
        </div>
      )
    case "row": {
      const justify = { start: "justify-start", center: "justify-center", end: "justify-end", between: "justify-between" }[block.align ?? "start"]
      return (
        <div className={`flex flex-wrap items-center gap-3 ${justify}`}>
          {block.children.map((child, i) => (
            <BlockNode key={i} block={child} />
          ))}
        </div>
      )
    }
    case "tabs": {
      const first = block.children[0]?.label
      if (!first) return null
      return (
        <Tabs defaultValue={first}>
          <TabsList>
            {block.children.map((tab) => (
              <TabsTrigger key={tab.label} value={tab.label}>
                {tab.label}
              </TabsTrigger>
            ))}
          </TabsList>
          {block.children.map((tab) => (
            <TabsContent key={tab.label} value={tab.label} className="pt-3">
              <BlockList blocks={tab.children} />
            </TabsContent>
          ))}
        </Tabs>
      )
    }
    case "tab":
      // Only meaningful as a direct child of `tabs`, handled above.
      return <BlockList blocks={block.children} />
    case "divider":
      return <hr className="border-border" />
    case "heading": {
      const Tag = (`h${block.level}` as const) as "h1" | "h2" | "h3" | "h4"
      const size = { 1: "text-xl", 2: "text-lg", 3: "text-base", 4: "text-sm" }[block.level]
      return <Tag className={`font-semibold ${size}`}>{block.text}</Tag>
    }
    case "text":
      return <p className={`text-sm ${block.tone === "muted" ? "text-muted-foreground" : ""}`}>{block.value}</p>
    case "alert":
      return (
        <Alert variant={toAlertVariant(block.variant)}>
          {block.title && <AlertTitle>{block.title}</AlertTitle>}
          <AlertDescription>{block.body}</AlertDescription>
        </Alert>
      )
    case "badge":
      return <Badge variant={toBadgeVariant(block.variant)}>{block.label}</Badge>
    case "stat": {
      const trendIcons: Record<"up" | "down" | "flat", string> = { up: "↑", down: "↓", flat: "→" }
      const trendIcon = block.trend ? trendIcons[block.trend] : null
      return (
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-xs text-muted-foreground">{block.label}</p>
          <p className="text-2xl font-semibold">
            {block.value} {trendIcon && <span className="text-sm text-muted-foreground">{trendIcon}</span>}
          </p>
          {block.hint && <p className="text-xs text-muted-foreground">{block.hint}</p>}
        </div>
      )
    }
    case "keyValue":
      return (
        <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
          {block.items.map((item, i) => (
            <React.Fragment key={i}>
              <dt className="text-muted-foreground">{item.label}</dt>
              <dd>{item.value}</dd>
            </React.Fragment>
          ))}
        </dl>
      )
    case "list": {
      const Tag = block.ordered ? "ol" : "ul"
      return (
        <Tag className={`space-y-1 text-sm ${block.ordered ? "list-decimal" : "list-disc"} ps-5`}>
          {block.items.map((item, i) => (
            <li key={i}>{item}</li>
          ))}
        </Tag>
      )
    }
    case "table":
      return (
        <Table>
          <TableHeader>
            <TableRow>
              {block.columns.map((c) => (
                <TableHead key={c.key}>{c.label}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {block.rows.map((row, i) => (
              <TableRow key={i}>
                {block.columns.map((c) => (
                  <TableCell key={c.key}>{row[c.key] ?? ""}</TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )
    case "button":
      return (
        <Button variant={toButtonVariant(block.variant)} onClick={() => (window.location.hash = block.href)}>
          {block.label}
        </Button>
      )
    case "icon": {
      const Icon = resolveTablerIcon(block.name)
      return <Icon className={`size-5 ${block.tone === "muted" ? "text-muted-foreground" : ""}`} />
    }
    case "code":
      return <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs">{block.content}</pre>
    case "math":
      // No KaTeX dependency on desktop yet — render the literal source rather
      // than silently dropping the block.
      return block.block ? (
        <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs">{block.expression}</pre>
      ) : (
        <code className="rounded bg-muted px-1 py-0.5 text-xs">{block.expression}</code>
      )
    case "markdown":
      // No Markdown renderer on desktop yet — preformatted source is safe
      // (never dangerouslySetInnerHTML) and still legible.
      return <pre className="whitespace-pre-wrap text-sm">{block.content}</pre>
    case "dataTable":
      return <DataTableRenderer block={block} />
    case "dataStat":
      return <DataStatRenderer block={block} />
    case "dataList":
      return <DataListRenderer block={block} />
    case "chart":
      return <ChartRenderer block={block} />
    case "selector":
      return <SelectorRenderer block={block} />
    case "modal":
      return <ModalRenderer block={block} />
    case "drawer":
      return <DrawerRenderer block={block} />
    case "form":
      return <FormRenderer block={block} />
    case "fieldArray":
      return <FieldArrayRenderer block={block} />
    case "submitButton":
      return <SubmitButtonRenderer label={block.label} variant={block.variant} />
    case "actionButton":
      return <ActionButtonRenderer block={block} />
    // ---- form-only inputs ----
    case "textInput":
    case "textArea":
    case "richTextInput":
    case "numberInput":
    case "select":
    case "checkbox":
    case "slider":
    case "dateInput":
    case "fileInput":
    case "colorInput":
    case "bilingualText":
    case "referenceSelect":
      if (!form) return <UnsupportedBlock reason={`${block.type} outside a form`} />
      if ("visibleWhen" in block && !isVisible(block.visibleWhen, form.values)) return null
      return <FormInput block={block} form={form} />
    default:
      return <UnsupportedBlock reason={(block as { type?: string }).type ?? "unknown"} />
  }
}

// ---------------------------------------------------------------- data-bound blocks

function DataTableRenderer({ block }: { block: DataTableBlock }) {
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, unknown>[]>(source, (data) => (Array.isArray(data) && data.length > 0 ? data : Array.isArray(data) ? [] : null))
  useRefetchOnSignal(state)

  if (state.status === "loading") return <Skeleton className="h-24 w-full" />
  if (state.status === "error") return <ErrorState title="Couldn't load this table" action={<Button onClick={state.retry}>Retry</Button>} />

  const rows = state.status === "empty" ? [] : state.data
  const refresh = state.status === "empty" || state.status === "ready" ? state.refresh : () => {}

  return (
    <DataTable<Record<string, unknown>>
      columns={block.columns.map((c) => ({
        id: c.key,
        accessorKey: c.key,
        header: c.label,
        enableSorting: c.sortable === true,
        enableColumnFilter: c.filterable === true,
      }))}
      data={rows}
      emptyStateTitle={block.emptyText ?? "Nothing here yet"}
      pagination={block.pageSize && block.pageSize > 0 ? { pageSize: block.pageSize } : undefined}
      rowActions={block.rowActions && block.rowActions.length > 0 ? (row) => <RowActions actions={block.rowActions!} row={row} onDone={refresh} /> : undefined}
    />
  )
}

function RowActions({ actions, row, onDone }: { actions: RowAction[]; row: Record<string, unknown>; onDone: () => void }) {
  const { openTarget } = React.useContext(MasterDetailContext)
  const fill = (template: string) => template.replace(/\{(\w+)\}/g, (_, key: string) => String(row[key] ?? ""))
  return (
    <div className="flex gap-2">
      {actions.map((action, i) => {
        if ("href" in action) {
          return (
            <Button key={i} variant="ghost" onClick={() => (window.location.hash = fill(action.href))}>
              {action.label}
            </Button>
          )
        }
        if ("open" in action) {
          return (
            <Button key={i} variant="ghost" onClick={() => openTarget(action.open, row)}>
              {action.label}
            </Button>
          )
        }
        return (
          <Button
            key={i}
            variant="ghost"
            onClick={async () => {
              if (action.confirm && !window.confirm(action.confirm)) return
              await submitPluginAction(fill(action.endpoint), action.method, {})
              onDone()
            }}
          >
            {action.label}
          </Button>
        )
      })}
    </div>
  )
}

function DataStatRenderer({ block }: { block: DataStatBlock }) {
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, unknown>>(source, (data) => (data && typeof data === "object" ? (data as Record<string, unknown>) : null))
  useRefetchOnSignal(state)

  if (state.status === "loading") return <Skeleton className="h-20 w-full" />
  if (state.status === "error") return <ErrorState title="Couldn't load this stat" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "No data"} />

  const value = state.data[block.valueField]
  const hint = block.hintField ? state.data[block.hintField] : undefined
  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <p className="text-xs text-muted-foreground">{block.label}</p>
      <p className="text-2xl font-semibold">{String(value ?? "")}</p>
      {hint !== undefined && <p className="text-xs text-muted-foreground">{String(hint)}</p>}
    </div>
  )
}

function DataListRenderer({ block }: { block: DataListBlock }) {
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, unknown>[]>(source, (data) => (Array.isArray(data) && data.length > 0 ? data : Array.isArray(data) ? [] : null))
  useRefetchOnSignal(state)

  const [filterText, setFilterText] = React.useState("")
  const [sortDir, setSortDir] = React.useState<"asc" | "desc" | null>(null)
  const [page, setPage] = React.useState(1)

  if (state.status === "loading") return <Skeleton className="h-16 w-full" />
  if (state.status === "error") return <ErrorState title="Couldn't load this list" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "Nothing here yet"} />

  const itemText = (row: Record<string, unknown>) => String(row[block.itemField] ?? "")

  let items = state.data
  if (block.filterable && filterText.trim() !== "") {
    const needle = filterText.trim().toLowerCase()
    items = items.filter((row) => itemText(row).toLowerCase().includes(needle))
  }
  if (block.sortable && sortDir) {
    items = [...items].sort((a, b) => itemText(a).localeCompare(itemText(b)) * (sortDir === "asc" ? 1 : -1))
  }

  const pageSize = block.pageSize && block.pageSize > 0 ? block.pageSize : items.length || 1
  const pageItems = block.pageSize && block.pageSize > 0 ? items.slice((page - 1) * pageSize, page * pageSize) : items

  const Tag = block.ordered ? "ol" : "ul"
  return (
    <div className="space-y-2">
      {(block.filterable || block.sortable) && (
        <div className="flex items-center gap-2">
          {block.filterable && (
            <Input
              value={filterText}
              onChange={(e) => {
                setFilterText(e.target.value)
                setPage(1)
              }}
              placeholder="Filter…"
              className="max-w-xs"
            />
          )}
          {block.sortable && (
            <Button type="button" variant="outline" onClick={() => setSortDir((d) => (d === "asc" ? "desc" : "asc"))}>
              Sort {sortDir === "asc" ? "A→Z" : sortDir === "desc" ? "Z→A" : ""}
            </Button>
          )}
        </div>
      )}
      {pageItems.length === 0 ? (
        <EmptyState title="No matches" />
      ) : (
        <Tag className={`space-y-1 text-sm ${block.ordered ? "list-decimal" : "list-disc"} ps-5`}>
          {pageItems.map((row, i) => (
            <li key={i}>{itemText(row)}</li>
          ))}
        </Tag>
      )}
      {block.pageSize && block.pageSize > 0 && items.length > 0 && (
        <Pagination page={page} perPage={pageSize} total={items.length} onPageChange={setPage} />
      )}
    </div>
  )
}

function ChartRenderer({ block }: { block: ChartBlock }) {
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, string | number>[]>(source, (data) => (Array.isArray(data) && data.length > 0 ? (data as Record<string, string | number>[]) : Array.isArray(data) ? [] : null))
  useRefetchOnSignal(state)

  if (state.status === "loading") return <Skeleton className="h-48 w-full" />
  if (state.status === "error") return <ErrorState title="Couldn't load this chart" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "No data"} />

  return <Chart type={block.chartType} data={state.data} series={block.series} xKey={block.xField} height={240} />
}

function SelectorRenderer({ block }: { block: SelectorBlock }) {
  const { selections, setSelection } = React.useContext(MasterDetailContext)
  const state = usePluginData<Record<string, unknown>[]>(block.source, (data) => (Array.isArray(data) && data.length > 0 ? data : null))

  if (state.status !== "ready") {
    return (
      <div className="space-y-1">
        <label className="text-sm font-medium">{block.label}</label>
        <Select disabled>
          <SelectTrigger>
            <SelectValue placeholder={state.status === "loading" ? "Loading…" : "No options"} />
          </SelectTrigger>
        </Select>
      </div>
    )
  }

  return (
    <div className="space-y-1">
      <label className="text-sm font-medium">{block.label}</label>
      <Select value={selections[block.name] ?? ""} onValueChange={(v) => setSelection(block.name, v)}>
        <SelectTrigger>
          <SelectValue placeholder={block.placeholder ?? "Select…"} />
        </SelectTrigger>
        <SelectContent>
          {state.data.map((row, i) => {
            const value = String(row[block.valueField] ?? "")
            return (
              <SelectItem key={i} value={value}>
                {String(row[block.labelField] ?? value)}
              </SelectItem>
            )
          })}
        </SelectContent>
      </Select>
    </div>
  )
}

function ModalRenderer({ block }: { block: ModalBlock }) {
  const { openTargets, openTarget, closeTarget } = React.useContext(MasterDetailContext)
  const open = openTargets[block.id] ?? false
  const sizeClass = { sm: "sm:max-w-sm", md: "sm:max-w-lg", lg: "sm:max-w-2xl" }[block.size ?? "md"]

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? openTarget(block.id) : closeTarget(block.id))}>
      {block.trigger && (
        <DialogTrigger asChild>
          <Button variant={toButtonVariant(block.variant)}>{block.trigger}</Button>
        </DialogTrigger>
      )}
      <DialogContent className={sizeClass}>
        <DialogHeader>
          <DialogTitle>{block.title}</DialogTitle>
        </DialogHeader>
        <ModalScopeContext.Provider value={block.id}>
          <BlockList blocks={block.children} />
        </ModalScopeContext.Provider>
      </DialogContent>
    </Dialog>
  )
}

function DrawerRenderer({ block }: { block: DrawerBlock }) {
  const { openTargets, openTarget, closeTarget } = React.useContext(MasterDetailContext)
  const open = openTargets[block.id] ?? false

  return (
    <Sheet open={open} onOpenChange={(next) => (next ? openTarget(block.id) : closeTarget(block.id))}>
      {block.trigger && (
        <SheetTrigger asChild>
          <Button variant="outline">{block.trigger}</Button>
        </SheetTrigger>
      )}
      <SheetContent side={block.side ?? "right"}>
        <SheetHeader>
          <SheetTitle>{block.title}</SheetTitle>
        </SheetHeader>
        <div className="space-y-4 px-4">
          <ModalScopeContext.Provider value={block.id}>
            <BlockList blocks={block.children} />
          </ModalScopeContext.Provider>
        </div>
      </SheetContent>
    </Sheet>
  )
}

// ---------------------------------------------------------------- form defaults

/** The input-leaf block types that own a value in a form's value map — the
 * SDK's INPUT_LEAF_TYPES, same list as web's `FORM_INPUT_TYPES`. */
const FORM_INPUT_TYPES: readonly string[] = [
  "textInput",
  "textArea",
  "numberInput",
  "select",
  "checkbox",
  "slider",
  "dateInput",
  "fileInput",
  "colorInput",
  "bilingualText",
  "referenceSelect",
  "richTextInput",
]

/** Flatten every input-leaf descendant of a form's children at ANY depth —
 * inputs nested in a section/card/grid/row/tab (or in a modal opened from
 * inside the form) belong to the enclosing form, because FormScopeContext is
 * a context and nesting depth never breaks it. A nested `form` owns its own
 * inputs, so we never descend into one; a `fieldArray` owns ONE value (its row
 * array) under its own name, so it is a leaf here and its row-scoped template
 * children are never flattened into the outer form. Mirrors
 * `collectFormInputs` in web/components/plugin/blocks/form-context.tsx. */
function collectFormInputs(blocks: Block[]): Block[] {
  const inputs: Block[] = []
  for (const block of blocks) {
    if (FORM_INPUT_TYPES.includes(block.type) || block.type === "fieldArray") {
      inputs.push(block)
      continue
    }
    if (block.type === "form") continue
    const nested = (block as { children?: unknown }).children
    if (Array.isArray(nested)) inputs.push(...collectFormInputs(nested as Block[]))
  }
  return inputs
}

/** `resolveDefault`'s seed-time twin: same `defaultFrom`-wins-over-`default`
 * precedence, but NORMALISED — an unset selector and a row field that is null
 * both count as unresolved, so the literal `default` still gets its turn and we
 * never seed a null the user never chose. `resolveFromContext` itself stays
 * raw: the `params` path reads it too, and tightening it there would change
 * which query params a data-bound block sends, which is not this fix's
 * business. Mirrors `resolveContextRef` in web's block-renderer. */
function resolveSeed(
  block: { default?: unknown; defaultFrom?: string },
  ctx: Pick<MasterDetailContextValue, "selections" | "rows">,
): unknown {
  const from = block.defaultFrom
  if (from !== undefined && from !== "") {
    const dot = from.indexOf(".")
    if (dot === -1) {
      const selected = ctx.selections[from]
      if (selected !== undefined && selected !== "") return selected
    } else {
      const field = ctx.rows[from.slice(0, dot)]?.[from.slice(dot + 1)]
      if (field !== undefined && field !== null) return field
    }
  }
  return block.default
}

/**
 * The resolved starting value of every input in a form, keyed by input name —
 * what `FormRenderer` seeds its value map with at mount, so the payload it
 * submits is the one the form is SHOWING. Without this a `defaultFrom`-seeded
 * input displayed the row's value but contributed nothing, and since the sync
 * endpoints are full-row replaces (`SyncController::update()` writes every
 * domain column as `values[col] ?? null`), editing one field silently nulled
 * every field the user had not touched — issue #847.
 *
 * Mirrors `collectDefaults` in web/components/plugin/blocks/form-context.tsx,
 * with two deliberate narrowings. Web seeds `{}` for every `bilingualText` and
 * `[]` for every zero-`min` `fieldArray` whether or not a default was declared;
 * seeding a value for an input that declares none is the SAME data loss in the
 * opposite direction against a full-row replace — it would overwrite stored
 * text with `{}` and a stored collection with `[]`. So an input with neither
 * `default` nor `defaultFrom` stays out of the map here, exactly as before, and
 * only what the form actually shows gets seeded.
 *
 * Each value is seeded in the type this renderer's own `onChange` would store,
 * so a seeded field and a typed one are indistinguishable downstream — a
 * payload whose types depend on whether the user touched the field would be
 * worse than either. (That is why `numberInput`/`slider` seed numbers where
 * web seeds strings: the web renderer stores strings on change. The two
 * renderers have always disagreed on the type of a TOUCHED numeric field;
 * making the seed agree with web would have widened that, not closed it.)
 */
function collectDefaults(
  children: Block[],
  ctx: Pick<MasterDetailContextValue, "selections" | "rows">,
): Record<string, unknown> {
  const defaults: Record<string, unknown> = {}
  for (const input of collectFormInputs(children)) {
    switch (input.type) {
      case "fieldArray": {
        // A required `min` is shown as `min` empty rows, so those rows have to
        // be in the payload; `min` 0 shows nothing and so seeds nothing.
        const min = typeof input.min === "number" && input.min > 0 ? input.min : 0
        if (min === 0) break
        const rowDefaults = collectDefaults(input.children, ctx)
        defaults[input.name] = Array.from({ length: min }, () => ({ ...rowDefaults }))
        break
      }
      case "checkbox": {
        const seeded = resolveSeed(input, ctx)
        // A row's `false`/`"0"` is a REAL seed, not an absent one — the
        // reported data loss included a boolean column being reset to 0.
        if (seeded !== undefined) defaults[input.name] = coerceBoolean(seeded)
        break
      }
      case "slider": {
        const seeded = resolveSeed(input, ctx)
        const numeric = Number(seeded)
        if (seeded !== undefined && Number.isFinite(numeric)) defaults[input.name] = numeric
        break
      }
      case "numberInput": {
        // Seeded verbatim: a JSON row gives a number, a literal `default` a
        // string, and the input renders either — coercing would only invent a
        // NaN when a row holds something non-numeric.
        const seeded = resolveSeed(input, ctx)
        if (seeded !== undefined) defaults[input.name] = seeded
        break
      }
      case "bilingualText": {
        // No literal `default` in the contract, so this is the `defaultFrom`
        // case only: a localized-text column arrives as an {ar, en} object.
        const seeded = resolveSeed(input, ctx)
        if (typeof seeded === "object" && seeded !== null && !Array.isArray(seeded)) {
          defaults[input.name] = seeded
        }
        break
      }
      case "textInput":
      case "textArea":
      case "richTextInput":
      case "select":
      case "dateInput":
      case "colorInput":
      case "referenceSelect": {
        const seeded = resolveSeed(input, ctx)
        // Stringified because that is what these inputs' own onChange stores;
        // a numeric row field would otherwise submit a number from an untouched
        // field and a string from a touched one.
        if (seeded !== undefined) defaults[input.name] = String(seeded)
        break
      }
      default:
        // `fileInput` is the only other leaf, and it has no `default` in the
        // contract — a file the user never picked has nothing to seed.
        break
    }
  }
  return defaults
}

// ---------------------------------------------------------------- form + inputs

function FormRenderer({ block }: { block: FormBlock }) {
  const modalId = React.useContext(ModalScopeContext)
  const masterDetail = React.useContext(MasterDetailContext)
  const { closeTarget } = masterDetail
  // Seeded ONCE, at mount, from the context as it stands then — the same lazy
  // initializer web uses, and deliberately not an effect that re-seeds when the
  // context changes. The context value changes on ANY selector move or overlay
  // open anywhere in the feature, so a re-seeding effect would wipe a half-typed
  // form because something unrelated on the screen moved. The case that
  // actually needs a new seed — a different row opened into this overlay — is
  // already covered: Dialog/Sheet unmount their content on close, so the next
  // open mounts a fresh FormRenderer and this initializer runs again against
  // the row `openTarget` has by then published.
  const [values, setValues] = React.useState<Record<string, unknown>>(() =>
    collectDefaults(block.children, masterDetail),
  )
  const [submitting, setSubmitting] = React.useState(false)
  const [result, setResult] = React.useState<{ ok: boolean; message?: string } | null>(null)
  const preload = usePluginData<Record<string, unknown>>(block.dataSource?.path ?? "__no_data_source__", (data) =>
    block.dataSource && data && typeof data === "object" ? (data as Record<string, unknown>) : null,
  )

  // A declared `dataSource` is the stored state of the thing being edited, so
  // it WINS over the seeded defaults — the merge used to run the other way,
  // which was harmless while the value map started empty but would now let a
  // block's literal `default` mask the value the server just returned. Nothing
  // of the user's is at risk in the flip: the fieldset below stays disabled
  // until the load settles, so there are no edits yet to lose. Both halves
  // match web's FormProvider.
  React.useEffect(() => {
    if (block.dataSource && preload.status === "ready") {
      setValues((prev) => ({ ...prev, ...preload.data }))
    }
    // Only re-seed when the preload data itself changes, not on every local edit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [block.dataSource, preload.status === "ready" ? preload.data : null])
  const isPreloading = block.dataSource !== undefined && preload.status === "loading"

  const setValue = React.useCallback((name: string, value: unknown) => {
    setValues((prev) => ({ ...prev, [name]: value }))
  }, [])
  const scope = React.useMemo<FormScope>(() => ({ values, setValue }), [values, setValue])

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setResult(null)
    try {
      const endpoint = interpolateEndpoint(block.submit.endpoint, masterDetail)
      const outcome = await submitPluginAction(endpoint, block.submit.method, values)
      if (outcome.ok) {
        // Inside a modal/drawer: closing IS the success feedback, and
        // `refresh: true` is what bumps refreshSignal — see
        // MasterDetailProvider's closeTarget doc — so the table/detail that
        // opened this form picks up the edit. At the top level, no overlay
        // to close, so show the inline confirmation instead.
        if (modalId) closeTarget(modalId, { refresh: true })
        else setResult({ ok: true })
      } else {
        setResult({ ok: false, message: outcome.error ?? outcome.issues?.map((i) => i.message).join(", ") ?? "Submission failed" })
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <FormScopeContext.Provider value={scope}>
      <fieldset disabled={submitting || isPreloading} className="space-y-4">
        <form onSubmit={submit} className="space-y-4">
          {result?.ok === false && (
            <Alert variant="destructive">
              <AlertDescription>{result.message}</AlertDescription>
            </Alert>
          )}
          {result?.ok === true && (
            <Alert variant="success">
              <AlertDescription>Submitted.</AlertDescription>
            </Alert>
          )}
          <BlockList blocks={block.children} />
        </form>
      </fieldset>
    </FormScopeContext.Provider>
  )
}

function FieldArrayRenderer({ block }: { block: FieldArrayBlock }) {
  const parent = React.useContext(FormScopeContext)
  const rows = (parent?.values[block.name] as Record<string, unknown>[] | undefined) ?? []

  const setRows = (next: Record<string, unknown>[]) => parent?.setValue(block.name, next)
  const addRow = () => setRows([...rows, {}])
  const removeRow = (index: number) => setRows(rows.filter((_, i) => i !== index))

  const min = block.min ?? 0
  const max = block.max ?? Infinity

  return (
    <div className="space-y-3 rounded-lg border border-border p-3">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium">{block.label}</span>
        <Button type="button" variant="outline" disabled={rows.length >= max} onClick={addRow}>
          Add {block.itemLabel ?? "item"}
        </Button>
      </div>
      {rows.map((row, index) => {
        const rowScope: FormScope = {
          values: row,
          setValue: (name, value) => {
            const next = [...rows]
            next[index] = { ...next[index], [name]: value }
            setRows(next)
          },
        }
        return (
          <div key={index} className="space-y-2 rounded-md bg-muted/40 p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">
                {block.itemLabel ?? "Item"} {index + 1}
              </span>
              <Button type="button" variant="ghost" disabled={rows.length <= min} onClick={() => removeRow(index)}>
                Remove
              </Button>
            </div>
            <FormScopeContext.Provider value={rowScope}>
              <BlockList blocks={block.children} />
            </FormScopeContext.Provider>
          </div>
        )
      })}
    </div>
  )
}

function SubmitButtonRenderer({ label, variant }: { label: string; variant?: ButtonVariant }) {
  return (
    <Button type="submit" variant={toButtonVariant(variant)}>
      {label}
    </Button>
  )
}

function ActionButtonRenderer({ block }: { block: { label: string; action: { method: "POST" | "PUT" | "PATCH"; endpoint: string }; confirm?: string; variant?: ButtonVariant } }) {
  const [busy, setBusy] = React.useState(false)
  return (
    <Button
      variant={toButtonVariant(block.variant)}
      disabled={busy}
      onClick={async () => {
        if (block.confirm && !window.confirm(block.confirm)) return
        setBusy(true)
        try {
          await submitPluginAction(block.action.endpoint, block.action.method, {})
        } finally {
          setBusy(false)
        }
      }}
    >
      {busy ? "Working…" : block.label}
    </Button>
  )
}

/** Every form-only input type, dispatched by a single component so
 * `BlockNode` doesn't need a case per input. `defaultFrom` (resolved via
 * `resolveDefault`, which checks the master-detail context) wins over a
 * literal `default` whenever both resolve — see `resolveDefault`'s own doc.
 * A value already present in `form.values` (user edit, or an earlier seed)
 * always wins over either — and since `collectDefaults` now seeds the map at
 * mount, that is the arm every defaulted input takes. The `?? resolveDefault`
 * fallbacks below are what an input the seed skips still renders from; they
 * are display only, which is exactly how #847 happened. */
function FormInput({ block, form }: { block: Extract<Block, { type: string }>; form: FormScope }) {
  const ctx = React.useContext(MasterDetailContext)

  switch (block.type) {
    case "textInput":
      return (
        <Input
          label={block.label}
          required={block.required}
          type={block.sensitive ? "password" : "text"}
          placeholder={block.placeholder}
          value={(form.values[block.name] as string | undefined) ?? (resolveDefault(block, ctx) as string | undefined) ?? ""}
          onChange={(e) => form.setValue(block.name, e.target.value)}
        />
      )
    case "textArea":
    case "richTextInput":
      return (
        <div className="space-y-1">
          <label className="text-sm font-medium">{block.label}</label>
          <Textarea
            required={block.required}
            rows={block.rows}
            value={(form.values[block.name] as string | undefined) ?? (resolveDefault(block, ctx) as string | undefined) ?? ""}
            onChange={(e) => form.setValue(block.name, e.target.value)}
          />
        </div>
      )
    case "numberInput":
      return (
        <Input
          label={block.label}
          type="number"
          required={block.required}
          min={block.min}
          max={block.max}
          step={block.step}
          value={(form.values[block.name] as string | number | undefined) ?? (resolveDefault(block, ctx) as string | undefined) ?? ""}
          onChange={(e) => form.setValue(block.name, e.target.value === "" ? "" : Number(e.target.value))}
        />
      )
    case "select":
      return (
        <div className="space-y-1">
          <label className="text-sm font-medium">{block.label}</label>
          <Select
            value={(form.values[block.name] as string | undefined) ?? (resolveDefault(block, ctx) as string | undefined) ?? ""}
            onValueChange={(v) => form.setValue(block.name, v)}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {block.options.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )
    case "checkbox":
      return (
        <label className="flex items-center gap-2 text-sm">
          <Checkbox
            checked={
              block.name in form.values
                ? coerceBoolean(form.values[block.name])
                : coerceBoolean(resolveDefault(block, ctx) ?? false)
            }
            onCheckedChange={(checked) => form.setValue(block.name, checked === true)}
          />
          {block.label}
        </label>
      )
    case "slider": {
      const current = (form.values[block.name] as number | undefined) ?? Number(resolveDefault(block, ctx) ?? block.min)
      return (
        <Slider label={block.label} min={block.min} max={block.max} step={block.step} value={[current]} onValueChange={([v]) => form.setValue(block.name, v)} />
      )
    }
    case "dateInput": {
      const current = (form.values[block.name] as string | undefined) ?? (resolveDefault(block, ctx) as string | undefined)
      return (
        <DatePicker
          label={block.label}
          required={block.required}
          value={current}
          onChange={(date) => form.setValue(block.name, date ? date.toISOString().slice(0, 10) : "")}
        />
      )
    }
    case "fileInput":
      return (
        <div className="space-y-1">
          <label className="text-sm font-medium">{block.label}</label>
          <input
            type="file"
            accept={block.accept}
            required={block.required}
            className="block w-full text-sm"
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (!file) return
              if (block.encoding === "base64") {
                const reader = new FileReader()
                reader.onload = () => form.setValue(block.name, reader.result)
                reader.readAsDataURL(file)
              } else {
                void file.text().then((text) => form.setValue(block.name, text))
              }
            }}
          />
        </div>
      )
    case "colorInput":
      return (
        <div className="space-y-1">
          <label className="text-sm font-medium">{block.label}</label>
          <input
            type="color"
            className="h-8 w-16 rounded border border-input"
            value={(form.values[block.name] as string | undefined) ?? (resolveDefault(block, ctx) as string | undefined) ?? "#000000"}
            onChange={(e) => form.setValue(block.name, e.target.value)}
          />
        </div>
      )
    case "bilingualText": {
      const value = (form.values[block.name] as BilingualValue | undefined) ?? (resolveDefault(block, ctx) as BilingualValue | undefined) ?? {}
      return (
        <BilingualInput
          label={block.label}
          required={block.required}
          arLabel={block.arLabel}
          enLabel={block.enLabel}
          value={value}
          onChange={(next) => form.setValue(block.name, next)}
        />
      )
    }
    case "referenceSelect":
      return <ReferenceSelectInput block={block} form={form} ctx={ctx} />
    default:
      return <UnsupportedBlock reason={block.type} />
  }
}

function ReferenceSelectInput({
  block,
  form,
  ctx,
}: {
  block: {
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
  form: FormScope
  ctx: Pick<MasterDetailContextValue, "selections" | "rows">
}) {
  const state = usePluginData<Record<string, unknown>[]>(block.source, (data) => (Array.isArray(data) ? data : null))
  const options = state.status === "ready" ? state.data : []
  return (
    <div className="space-y-1">
      <label className="text-sm font-medium">{block.label}</label>
      <Select
        value={(form.values[block.name] as string | undefined) ?? (resolveDefault(block, ctx) as string | undefined) ?? ""}
        onValueChange={(v) => form.setValue(block.name, v)}
      >
        <SelectTrigger>
          <SelectValue placeholder={state.status === "loading" ? "Loading…" : (block.placeholder ?? "Select…")} />
        </SelectTrigger>
        <SelectContent>
          {options.map((row, i) => {
            const value = String(row[block.valueField] ?? "")
            return (
              <SelectItem key={i} value={value}>
                {String(row[block.labelField] ?? value)}
              </SelectItem>
            )
          })}
        </SelectContent>
      </Select>
    </div>
  )
}
