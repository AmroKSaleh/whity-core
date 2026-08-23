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
  AccessGateBlock,
  Block,
  ChartBlock,
  DataListBlock,
  DataRecordBlock,
  DataStatBlock,
  DataTableBlock,
  DrawerBlock,
  FieldArrayBlock,
  FlowBlock,
  FormBlock,
  InboxBlock,
  ItemAction,
  ModalBlock,
  OuScopeKind,
  OuScopePickerBlock,
  OuScopeValue,
  PluginFeature,
  RecordFact,
  RecordFieldsBlock,
  RowAction,
  SelectorBlock,
  SourceParam,
  TimelineBlock,
  VisibleWhen,
} from "./types"
import { FLOW_MAX_NODES, OU_SCOPE_KINDS } from "./types"
import { usePluginData, type PluginDataState } from "./use-plugin-data"
import { usePermittedActions, type PermittedActionCheck } from "./use-permitted-actions"

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
  /** #883: a `dataRecord` publishes its fetched record under its own id, into
   * the SAME `rows` map an `open` row action writes — so `{id}.{field}` means
   * one thing whether the record came from a route, a selector, or a clicked
   * row. Separate from `openTarget` only because publishing a record must not
   * also mark an overlay open; the addressing is deliberately identical. */
  publishRecord: (id: string, fields: Record<string, unknown>, facts: RecordFact[]) => void
  /** The DECLARATION behind each published record: which fields it names and
   * under which labels. Held beside `rows` rather than inside it because `rows`
   * is the addressing surface every `{id}.{field}` reference resolves against,
   * and a parallel label map in there would make `{rec.label}` mean something.
   * Provider-level rather than scoped to the `dataRecord`'s subtree, so a
   * `recordFields` that is its SIBLING resolves too — `from` names a record,
   * not a position in the tree. */
  recordFacts: Record<string, RecordFact[]>
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
  publishRecord: () => {},
  recordFacts: {},
  refreshSignal: 0,
})

/** #883: the reserved binding a host seeds with the record its ROUTE is about.
 * Kept in step with the SDK's `BlockValidator::PAGE_RECORD_BINDING`, which
 * refuses a `selector` that would shadow it. */
export const PAGE_RECORD_BINDING = "record"

function MasterDetailProvider({ children, record }: { children: React.ReactNode; record?: string }) {
  const [selections, setSelections] = React.useState<Record<string, string>>(
    record !== undefined && record !== "" ? { [PAGE_RECORD_BINDING]: record } : {},
  )
  const [rows, setRows] = React.useState<Record<string, Record<string, unknown>>>({})
  const [recordFacts, setRecordFacts] = React.useState<Record<string, RecordFact[]>>({})
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
  /** Bails out when nothing changed. `dataRecord` publishes from an effect on
   * every settled fetch and the projection builds a fresh object each time, so
   * an unconditional setState here would re-render the feature tree forever. */
  const publishRecord = React.useCallback((id: string, fields: Record<string, unknown>, facts: RecordFact[]) => {
    setRows((prev) => {
      const current = prev[id]
      if (current !== undefined && shallowEqualRecords(current, fields)) return prev
      return { ...prev, [id]: fields }
    })
    setRecordFacts((prev) => (prev[id] === facts ? prev : { ...prev, [id]: facts }))
  }, [])

  // The route's record is a PROP, so it has to survive a re-render that changes
  // it (one record page navigating to another) without discarding selections
  // the user has made on the screen.
  const seededRecord = React.useRef(record)
  React.useEffect(() => {
    if (seededRecord.current === record) return
    seededRecord.current = record
    setSelections((prev) => ({ ...prev, [PAGE_RECORD_BINDING]: record ?? "" }))
  }, [record])

  const value = React.useMemo<MasterDetailContextValue>(
    () => ({ selections, setSelection, rows, recordFacts, openTargets, openTarget, closeTarget, publishRecord, refreshSignal }),
    [selections, setSelection, rows, recordFacts, openTargets, openTarget, closeTarget, publishRecord, refreshSignal],
  )
  return <MasterDetailContext.Provider value={value}>{children}</MasterDetailContext.Provider>
}

/** Whether two published records hold the same values, compared one level
 * deep. Mirrors the web renderer's `shallowEqualRecords` — one level is enough
 * because the projection's values are whatever the payload held for the
 * declared fields, and a nested object that changed identity but not content
 * costs one extra render rather than a loop. */
function shallowEqualRecords(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
  const aKeys = Object.keys(a)
  if (aKeys.length !== Object.keys(b).length) return false
  return aKeys.every((key) => Object.is(a[key], b[key]))
}

/** #883: the value a literal leaf actually shows — the record field named by
 * its `...From` twin when that resolves, otherwise the declared literal. The
 * literal is the FALLBACK rather than the alternative, which is why the
 * contract keeps it required: a record page needs a title before its record
 * has arrived, and on a screen where nothing publishes one at all. */
function boundText(
  ctx: Pick<MasterDetailContextValue, "selections" | "rows">,
  literal: string,
  ref: string | undefined,
): string {
  if (ref === undefined || ref === "") return literal
  const resolved = resolveFromContext(ref, ctx)
  if (resolved === undefined || resolved === null || resolved === "") return literal
  return String(resolved)
}

/** #883: project a fetched payload down to the facts the declaration NAMED.
 *
 * The structural half of the #895 guard, and deliberately the only path by
 * which a record reaches the master-detail context. A payload's `manageable`,
 * `canEdit` or `mayModify` is not filtered out here so much as never picked up:
 * the projection reads the declared field names and nothing else. The SDK
 * validator refuses the eleven names #897 knows; this refuses everything that
 * was not asked for. */
function projectRecordFacts(payload: Record<string, unknown>, fields: RecordFact[]): Record<string, unknown> {
  const facts: Record<string, unknown> = {}
  for (const fact of fields) {
    if (typeof fact?.field === "string" && fact.field !== "") facts[fact.field] = payload[fact.field]
  }
  return facts
}

/** A published fact as display text. `null`/`undefined` become an EM DASH
 * rather than an empty cell or "null" — the record-page shell's answer for a
 * value the server has not stated, kept identical to the web renderer's so a
 * described record page does not disagree with itself across platforms. */
function formatFactValue(value: unknown): string {
  if (value === null || value === undefined) return "—"
  if (typeof value === "boolean") return value ? "Yes" : "No"
  if (typeof value === "object") return JSON.stringify(value)
  return String(value)
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

/**
 * #883: a `dataRecord`'s EFFECTIVE source, with its `{token}` segments
 * substituted from the master-detail context — or `null` when any token is
 * still unresolved.
 *
 * `null` MATTERS, and it is the one place this differs from `interpolateEndpoint`
 * above. That one substitutes `""` for an unresolved token, which is right for a
 * submit the user explicitly triggered. Here it would be a silent bug of the
 * worst kind: `/api/v1/things/{record}` with nothing bound becomes
 * `/api/v1/things/`, which is very often the COLLECTION endpoint — so the block
 * would fetch every record the caller can see and render it as "the record this
 * page is about". Not fetching is the only honest answer to "which record?"
 * when nothing has said.
 */
function useResolvedRecordSource(baseSource: string, params?: SourceParam[]): string | null {
  const ctx = React.useContext(MasterDetailContext)
  return React.useMemo(() => {
    // Split on the tokens rather than replacing through a callback, matching
    // the web renderer exactly: a callback recording "something did not
    // resolve" in a closure variable is a reassignment during render. Splitting
    // on a CAPTURING pattern puts every token at an odd index, so this is a map
    // and a join with nothing mutable in it.
    const parts = baseSource.split(/(\{[^{}]*\})/)
    const resolvedParts = parts.map((part, index) => {
      if (index % 2 === 0) return part
      const value = resolveFromContext(part.slice(1, -1), ctx)
      return value === undefined || value === null || value === "" ? null : encodeURIComponent(String(value))
    })
    if (resolvedParts.some((part) => part === null)) return null
    const substituted = resolvedParts.join("")
    if (!params || params.length === 0) return substituted
    const pairs = params
      .map((param) => {
        const value = resolveFromContext(param.from, ctx)
        return value === undefined || value === null || value === ""
          ? null
          : `${encodeURIComponent(param.param)}=${encodeURIComponent(String(value))}`
      })
      .filter((pair): pair is string => pair !== null)
    if (pairs.length === 0) return substituted
    return `${substituted}${substituted.includes("?") ? "&" : "?"}${pairs.join("&")}`
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [baseSource, params, ctx.selections, ctx.rows])
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

// ---------------------------------------------------------------- #909 access

/** What a gate's question currently answers.
 *
 * Neither unsettled state is a synonym for refused: an unanswered gate renders
 * NEITHER branch, because showing the read-only rendering for a frame and
 * replacing it with the editor is a worse lie than showing nothing for a frame.
 * They are told apart because they look different — `"pending"` is in flight and
 * gets a skeleton, while `"unasked"` (an unresolved endpoint token, or an id
 * nothing declared) gets nothing: a skeleton that never resolves promises an
 * answer no one is fetching. */
type AccessAnswer = "unasked" | "pending" | "allowed" | "refused"

/**
 * The access namespace: gate id -> the host's answer.
 *
 * SEPARATE FROM THE MASTER-DETAIL CONTEXT ON PURPOSE — the #895 property
 * restated for #909. A record's published fields live in `MasterDetailContext.rows`,
 * which `resolveFromContext` reads, and that function is the single resolver
 * behind every fact binding (`textFrom`/`valueFrom`/`labelFrom`/`hintFrom`) and
 * every plumbing binding (`defaultFrom`, `params.from`, a `{token}` in a
 * source). It does not read this map. So a page can ACT on what the caller may
 * do and still cannot SAY it about the record.
 */
interface AccessScope {
  answer: (gateId: string) => AccessAnswer
}

const AccessContext = React.createContext<AccessScope | null>(null)

/** One gate's declaration, as collected from the tree. */
interface CollectedGate {
  id: string
  method: string
  endpoint: string
}

/** Collect every `accessGate` in a tree, in document order, descending through
 * BOTH child slots — `otherwise` holds real blocks and can hold nested gates.
 * Derived from the tree rather than registered by the gates as they mount: the
 * declarations are static, and a registration pass would cost a second render
 * with every gated region absent in between. */
function collectAccessGates(blocks: Block[] | undefined, into: CollectedGate[] = []): CollectedGate[] {
  if (!Array.isArray(blocks)) return into
  for (const block of blocks) {
    if (block === null || typeof block !== "object") continue
    if (
      block.type === "accessGate" &&
      typeof block.id === "string" &&
      block.id !== "" &&
      typeof block.check === "object" &&
      block.check !== null &&
      typeof block.check.method === "string" &&
      typeof block.check.endpoint === "string" &&
      block.check.endpoint !== "" &&
      !into.some((gate) => gate.id === block.id)
    ) {
      into.push({ id: block.id, method: block.check.method, endpoint: block.check.endpoint })
    }
    const node = block as { children?: Block[]; otherwise?: Block[] }
    collectAccessGates(node.children, into)
    collectAccessGates(node.otherwise, into)
  }
  return into
}

/** Substitute a gate endpoint's `{token}` segments, or null when any is
 * unresolved. Null means NOT ASKED, exactly as it does for a `dataRecord.source`:
 * a half-substituted path names a different route with a different gate. */
function resolveGateEndpoint(endpoint: string, ctx: Pick<MasterDetailContextValue, "selections" | "rows">): string | null {
  const parts = endpoint.split(/(\{[^{}]*\})/)
  const resolved = parts.map((part, index) => {
    if (index % 2 === 0) return part
    const value = resolveFromContext(part.slice(1, -1), ctx)
    return value === undefined || value === null || value === "" ? null : encodeURIComponent(String(value))
  })
  return resolved.some((part) => part === null) ? null : resolved.join("")
}

/** The methods the host will resolve. Mirrors `BlockValidator::ACCESS_CHECK_METHODS`. */
const ACCESS_CHECK_METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE"] as const

function isAccessCheckMethod(value: string): value is PermittedActionCheck["method"] {
  return (ACCESS_CHECK_METHODS as readonly string[]).includes(value)
}

/** Resolves every gate on the screen in ONE batch through the offline host's own
 * authority (`POST /__whity/permitted-actions`) — the same endpoint, the same
 * fail-closed policy and the same batching argument as the `inbox` block. */
function AccessProvider({ blocks, children }: { blocks: Block[]; children: React.ReactNode }) {
  const ctx = React.useContext(MasterDetailContext)
  const gates = React.useMemo(() => collectAccessGates(blocks), [blocks])

  const { checks, resolvable } = React.useMemo(() => {
    const list: PermittedActionCheck[] = []
    const ids = new Set<string>()
    for (const gate of gates) {
      const method = gate.method.toUpperCase()
      if (!isAccessCheckMethod(method)) continue
      const path = resolveGateEndpoint(gate.endpoint, ctx)
      if (path === null) continue
      list.push({ ref: gate.id, method, path })
      ids.add(gate.id)
    }
    return { checks: list, resolvable: ids }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gates, ctx.selections, ctx.rows])

  const batchKey = React.useMemo(() => JSON.stringify(checks), [checks])
  const permitted = usePermittedActions(checks, batchKey)
  const status = permitted.status
  const isAllowed = permitted.isAllowed

  const value = React.useMemo<AccessScope>(
    () => ({
      answer: (gateId: string): AccessAnswer => {
        if (!resolvable.has(gateId)) return "unasked"
        if (status === "loading") return "pending"
        // An error is a REFUSAL, not a pending state: the alternative is a
        // region that never resolves, which for the read-only pair is a record
        // page with no body at all when the resolver is down.
        return status === "ready" && isAllowed(gateId) ? "allowed" : "refused"
      },
    }),
    [resolvable, status, isAllowed],
  )

  return <AccessContext.Provider value={value}>{children}</AccessContext.Provider>
}

/** The two renderings of a gated region, declared together so they cannot drift
 * apart. A pending answer renders a skeleton and NOT the refused branch — "you
 * may not edit this" is a statement, and stating it before the answer arrives
 * states something not yet known. */
function AccessGateRenderer({ block }: { block: AccessGateBlock }) {
  const access = React.useContext(AccessContext)
  const answer = access?.answer(block.id) ?? "unasked"
  const permitted = Array.isArray(block.children) ? block.children : []
  const refused = Array.isArray(block.otherwise) ? block.otherwise : []

  if (permitted.length === 0 && refused.length === 0) return null
  if (answer === "unasked") return null
  if (answer === "pending") return <Skeleton className="h-16 w-full" data-slot="block-access-pending" />

  const shown = answer === "allowed" ? permitted : refused
  if (shown.length === 0) return null
  return <BlockList blocks={shown} />
}

/** Normalize a form value / rule operand to a comparable string, matching the
 * web renderer exactly: booleans become `"true"`/`"false"` so a checkbox matches
 * `equals: true` and `equals: "true"` alike, and everything else is
 * `String()`-coerced so a numeric `equals: 5` matches a field holding `"5"`.
 * This renderer used to compare with `===` and therefore disagreed with the web
 * one on exactly those two cases. */
function normalizeVisibilityOperand(value: unknown): string {
  if (typeof value === "boolean") return value ? "true" : "false"
  if (value !== null && typeof value === "object") return " object"
  return value === undefined || value === null ? "" : String(value)
}

/**
 * Evaluate a block's optional `visibleWhen` facet (WC-532 A3, widened by #909).
 *
 * FACTS FAIL OPEN, AUTHORITY FAILS CLOSED. A `field`/`from` rule that cannot be
 * evaluated leaves the block visible; an `access` rule that has not been
 * answered hides it, whichever polarity it asked for — a control drawn before
 * its permission is known is a control drawn for somebody who may not have it.
 */
function isVisible(
  visibleWhen: VisibleWhen | undefined,
  values: Record<string, unknown>,
  ctx: Pick<MasterDetailContextValue, "selections" | "rows">,
  access: AccessScope | null,
): boolean {
  if (!visibleWhen) return true

  if (typeof visibleWhen.access === "string" && visibleWhen.access !== "") {
    const answer = access?.answer(visibleWhen.access) ?? "unasked"
    if (answer === "unasked" || answer === "pending") return false
    if (typeof visibleWhen.equals !== "boolean" || visibleWhen.in !== undefined) return false
    return (answer === "allowed") === visibleWhen.equals
  }

  let current: string | undefined
  if (typeof visibleWhen.from === "string" && visibleWhen.from !== "") {
    const resolved = resolveFromContext(visibleWhen.from, ctx)
    current = resolved === undefined || resolved === null ? undefined : normalizeVisibilityOperand(resolved)
  } else if (typeof visibleWhen.field === "string" && visibleWhen.field !== "") {
    current = normalizeVisibilityOperand(values[visibleWhen.field])
  }

  if (current === undefined) return true
  if (visibleWhen.equals !== undefined) return current === normalizeVisibilityOperand(visibleWhen.equals)
  if (visibleWhen.in !== undefined) return visibleWhen.in.some((v) => current === normalizeVisibilityOperand(v))
  return true
}

// ---------------------------------------------------------------- public entry

export function BlockRenderer({ feature, record }: { feature: PluginFeature; record?: string }) {
  const blocks = feature.blocks
  if (!Array.isArray(blocks)) {
    return <ErrorState title="No content" description="This feature declared no renderable blocks." />
  }
  return (
    <MasterDetailProvider record={record}>
      {/* Inside the master-detail provider: a gate's endpoint may carry
          `{record}` and resolves through the same context every source does. */}
      <AccessProvider blocks={blocks}>
        <BlockList blocks={blocks} />
      </AccessProvider>
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
  // #883: read once at the top rather than per case — a `switch` body cannot
  // call a hook, and the literal leaves below each need the context to resolve
  // their `...From` twin. The cost is that EVERY node now subscribes to the
  // master-detail context and re-renders when a selection changes, where the
  // web renderer subscribes only from the four leaves that can bind. Accepted
  // rather than worked around: splitting the switch into per-leaf components to
  // narrow the subscription would restructure the one file that most needs to
  // stay diffable against its twin, to save re-rendering nodes that render
  // text.
  const md = React.useContext(MasterDetailContext)
  const access = React.useContext(AccessContext)

  // #909: `visibleWhen` is carried by EVERY block type now, so it is evaluated
  // ONCE here rather than per case. It used to be checked in the three branches
  // that could carry it, which is exactly the shape that makes a universal facet
  // impossible to add without missing one.
  if (!isVisible(block.visibleWhen, form?.values ?? {}, md, access)) return null

  switch (block.type) {
    case "section": {
      return (
        <div className="space-y-3">
          {block.title && <h3 className="text-sm font-semibold">{block.title}</h3>}
          <BlockList blocks={block.children} />
        </div>
      )
    }
    case "card": {
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
      // A `tab`'s children render from here rather than through `BlockNode`, so
      // this is the one place a `visibleWhen` would otherwise be silently
      // ignored — a contract that says every block carries the facet has to mean
      // it (#909), and hiding a tab the caller may not open is the point of
      // carrying it here at all.
      const tabs = block.children.filter((tab) => isVisible(tab.visibleWhen, form?.values ?? {}, md, access))
      const first = tabs[0]?.label
      if (!first) return null
      return (
        <Tabs defaultValue={first}>
          <TabsList>
            {tabs.map((tab) => (
              <TabsTrigger key={tab.label} value={tab.label}>
                {tab.label}
              </TabsTrigger>
            ))}
          </TabsList>
          {tabs.map((tab) => (
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
    case "accessGate":
      return <AccessGateRenderer block={block} />
    case "divider":
      return <hr className="border-border" />
    case "heading": {
      const Tag = (`h${block.level}` as const) as "h1" | "h2" | "h3" | "h4"
      const size = { 1: "text-xl", 2: "text-lg", 3: "text-base", 4: "text-sm" }[block.level]
      return <Tag className={`font-semibold ${size}`}>{boundText(md, block.text, block.textFrom)}</Tag>
    }
    case "text":
      return (
        <p className={`text-sm ${block.tone === "muted" ? "text-muted-foreground" : ""}`}>
          {boundText(md, block.value, block.valueFrom)}
        </p>
      )
    case "alert":
      return (
        <Alert variant={toAlertVariant(block.variant)}>
          {block.title && <AlertTitle>{block.title}</AlertTitle>}
          <AlertDescription>{block.body}</AlertDescription>
        </Alert>
      )
    case "badge":
      return <Badge variant={toBadgeVariant(block.variant)}>{boundText(md, block.label, block.labelFrom)}</Badge>
    case "stat": {
      const trendIcons: Record<"up" | "down" | "flat", string> = { up: "↑", down: "↓", flat: "→" }
      const trendIcon = block.trend ? trendIcons[block.trend] : null
      const statValue = boundText(md, block.value, block.valueFrom)
      const statHint = boundText(md, block.hint ?? "", block.hintFrom)
      return (
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-xs text-muted-foreground">{block.label}</p>
          <p className="text-2xl font-semibold">
            {statValue} {trendIcon && <span className="text-sm text-muted-foreground">{trendIcon}</span>}
          </p>
          {statHint && <p className="text-xs text-muted-foreground">{statHint}</p>}
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
    case "timeline":
      return <TimelineRenderer block={block} />
    case "inbox":
      return <InboxRenderer block={block} />
    case "flow":
      return <FlowRenderer block={block} />
    case "dataRecord":
      return <DataRecordRenderer block={block} />
    case "recordFields":
      return <RecordFieldsRenderer block={block} />
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
    case "ouScopePicker":
      if (!form) return <UnsupportedBlock reason={`${block.type} outside a form`} />
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

/**
 * DataRecordRenderer (#883) — the record-bound primitive.
 *
 * Fetches ONE resource, publishes the fields its declaration NAMES into the
 * master-detail context under `block.id`, and renders its children beneath. It
 * owns loading and failure for the whole subtree, which is the reason it is a
 * container: a record page assembled from a dozen leaves that each own their
 * own skeleton shows a dozen skeletons resolving in an arbitrary order, and a
 * record that failed to load renders as a page of empty fields rather than as a
 * page that could not be loaded.
 */
function DataRecordRenderer({ block }: { block: DataRecordBlock }) {
  const { publishRecord, rows } = React.useContext(MasterDetailContext)
  const source = useResolvedRecordSource(block.source, block.params)
  // `usePluginData` is a hook, so it cannot be skipped when the source has not
  // resolved. An empty source is never fetched (the hook bails), which keeps
  // hook order stable without requesting a record nobody has named yet.
  const state = usePluginData<Record<string, unknown>>(source ?? "", (data) =>
    data && typeof data === "object" && !Array.isArray(data) ? (data as Record<string, unknown>) : null,
  )
  useRefetchOnSignal(state)

  // Keyed on the fetched payload rather than on `state`, which is a fresh object
  // every render (see the web renderer for the full rationale). `source === null`
  // forces it back to null so a record whose token stopped resolving stops being
  // published, rather than leaving a sibling `recordFields` rendering the record
  // the page is no longer about.
  const fetched = state.status === "ready" && source !== null ? state.data : null
  const facts = React.useMemo(
    () => (fetched === null ? null : projectRecordFacts(fetched, block.fields)),
    [fetched, block.fields],
  )

  React.useEffect(() => {
    if (facts === null) return
    publishRecord(block.id, facts, block.fields)
  }, [facts, publishRecord, block.id, block.fields])

  // Nothing has named a record yet — a record page before its route resolves,
  // or a detail pane before the user has picked a master row. Deliberately the
  // same shape as `empty` rather than an error: no record chosen is a state,
  // not a failure.
  if (source === null) return <EmptyState title={block.emptyText ?? "No record selected"} />
  if (state.status === "loading") return <Skeleton className="h-24 w-full" />
  if (state.status === "error")
    return <ErrorState title="Couldn't load this record" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "This record is not available"} />

  // The record is fetched but not yet IN CONTEXT. `publishRecord` runs from an
  // effect, which commits after this render — so rendering the children now
  // mounts them against an empty context, and everything that reads the record
  // AT MOUNT rather than on every render silently gets nothing. A form's
  // `defaultFrom` is exactly that: seeded once, when the input mounts (see
  // `collectDefaults`). Holding the loading state for one extra frame is what
  // makes "a record page is a form WITH its record" true instead of nearly true.
  if (rows[block.id] === undefined) return <Skeleton className="h-24 w-full" />

  return (
    <div className="space-y-4">
      <BlockList blocks={block.children} />
    </div>
  )
}

/**
 * RecordFieldsRenderer (#883) — the data-bound `keyValue`.
 *
 * Reads the record published under `block.from` and renders its declared facts
 * as a description list. `fields` picks a subset in the order given; omitted,
 * every declared fact is shown. An unresolvable `from` renders nothing, which
 * is the no-op an unresolvable reference already is everywhere else here.
 */
function RecordFieldsRenderer({ block }: { block: RecordFieldsBlock }) {
  const { rows, recordFacts } = React.useContext(MasterDetailContext)
  const row = rows[block.from]
  const declared = recordFacts[block.from]

  if (row === undefined || declared === undefined) return null

  const wanted =
    Array.isArray(block.fields) && block.fields.length > 0
      ? block.fields
          .map((name) => declared.find((fact) => fact.field === name))
          .filter((fact): fact is RecordFact => fact !== undefined)
      : declared

  if (wanted.length === 0) return <EmptyState title={block.emptyText ?? "No fields to show"} />

  return (
    <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
      {wanted.map((fact) => (
        <React.Fragment key={fact.field}>
          <dt className="text-muted-foreground">{fact.label}</dt>
          <dd>{formatFactValue(row[fact.field])}</dd>
        </React.Fragment>
      ))}
    </dl>
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

// ---------------------------------------------------------------- workflow blocks (#868)

/** A stable empty row set, so the memoized item list is not rebuilt every render. */
const EMPTY_ROWS: Record<string, unknown>[] = []

/**
 * The `ref` under which one (item, action) pair is resolved and looked up.
 * The SDK forbids whitespace in an action `key`, so a space is unambiguous.
 * Must match the web renderer's `actionRef` — both halves of a resolved batch
 * are keyed by it.
 */
function actionRef(itemId: string, actionKey: string): string {
  return `${itemId} ${actionKey}`
}

/** Substitute `{field}` placeholders from a row, matching `RowActions`' `fill`. */
function fillTemplate(template: string, row: Record<string, unknown>): string {
  return template.replace(/\{(\w+)\}/g, (_, key: string) => String(row[key] ?? ""))
}

/**
 * TimelineRenderer — an ordered, append-only event list: actor, action,
 * timestamp, an optional note, and an optional from → to pair. Read-only by
 * construction: the contract carries no endpoint and no verb.
 *
 * Mirrors the web renderer's TimelineRenderer field-for-field, including the
 * client-side `pageSize` slice over the rows one fetch returned.
 */
function TimelineRenderer({ block }: { block: TimelineBlock }) {
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, unknown>[]>(source, (data) =>
    Array.isArray(data) && data.length > 0 ? data : null,
  )
  useRefetchOnSignal(state)

  const [page, setPage] = React.useState(1)

  if (state.status === "loading") return <Skeleton className="h-24 w-full" />
  if (state.status === "error")
    return <ErrorState title="Couldn't load this timeline" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "No events recorded"} />

  const events = state.data.map((row) => ({
    actor: String(row[block.actorField] ?? ""),
    action: String(row[block.actionField] ?? ""),
    timestamp: String(row[block.timestampField] ?? ""),
    note: block.noteField !== undefined ? String(row[block.noteField] ?? "") : "",
    from: block.fromField !== undefined ? String(row[block.fromField] ?? "") : "",
    to: block.toField !== undefined ? String(row[block.toField] ?? "") : "",
  }))

  const paginate = block.pageSize !== undefined && block.pageSize > 0
  const pageSize = paginate ? block.pageSize! : events.length || 1
  const pageEvents = paginate ? events.slice((page - 1) * pageSize, page * pageSize) : events

  return (
    <div className="space-y-2">
      {/* An ordered list, semantically: the order IS the information. */}
      <ol className="relative space-y-4 border-s ps-5">
        {pageEvents.map((event, i) => (
          <li key={i} className="relative">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <span className="text-sm font-medium">{event.actor}</span>
              <span className="text-sm">{event.action}</span>
              <span className="text-xs text-muted-foreground">{event.timestamp}</span>
            </div>
            {(event.from !== "" || event.to !== "") && (
              <div className="mt-1 flex flex-wrap items-center gap-1.5">
                {event.from !== "" && <Badge variant="outline">{event.from}</Badge>}
                <span className="text-xs text-muted-foreground" aria-hidden>
                  &rarr;
                </span>
                {event.to !== "" && <Badge variant="secondary">{event.to}</Badge>}
              </div>
            )}
            {event.note !== "" && <p className="mt-1 text-xs text-muted-foreground">{event.note}</p>}
          </li>
        ))}
      </ol>
      {paginate && events.length > 0 && (
        <Pagination page={page} perPage={pageSize} total={events.length} onPageChange={setPage} />
      )}
    </div>
  )
}

/**
 * One resolved action button on an inbox item. Rendered ONLY when the host
 * answered that this caller may make this exact request, so a refused or
 * unresolved action is absent rather than present-and-disabled.
 */
function InboxActionButton({ action, path, onDone }: { action: ItemAction; path: string; onDone: () => void }) {
  const [busy, setBusy] = React.useState(false)
  return (
    <Button
      variant={toButtonVariant(action.variant)}
      disabled={busy}
      onClick={async () => {
        if (action.confirm && !window.confirm(action.confirm)) return
        setBusy(true)
        await submitPluginAction(path, action.method, {})
        setBusy(false)
        onDone()
      }}
    >
      {action.label}
    </Button>
  )
}

/**
 * InboxRenderer — the items awaiting the current user, each carrying the actions
 * that user may actually take on it.
 *
 * The seam, identical to the web renderer's: the PLUGIN supplies the items
 * (`source`), the HOST resolves which of the declared `actions` this caller may
 * take on each (`usePermittedActions`). Fail-closed while resolving, so the
 * action row fills in rather than emptying out.
 */
function InboxRenderer({ block }: { block: InboxBlock }) {
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, unknown>[]>(source, (data) =>
    Array.isArray(data) && data.length > 0 ? data : null,
  )
  useRefetchOnSignal(state)

  const [page, setPage] = React.useState(1)

  const rows = state.status === "ready" ? state.data : EMPTY_ROWS

  const items = React.useMemo(
    () =>
      rows.map((row) => ({
        id: String(row[block.idField] ?? ""),
        title: String(row[block.titleField] ?? ""),
        subtitle: block.subtitleField !== undefined ? String(row[block.subtitleField] ?? "") : "",
        timestamp: block.timestampField !== undefined ? String(row[block.timestampField] ?? "") : "",
        status: block.statusField !== undefined ? String(row[block.statusField] ?? "") : "",
        raw: row,
      })),
    [rows, block.idField, block.titleField, block.subtitleField, block.timestampField, block.statusField],
  )

  // One check per (item, action): the CONCRETE request the button would make,
  // templated from the item exactly as it will be at click time.
  const checks = React.useMemo<PermittedActionCheck[]>(() => {
    const out: PermittedActionCheck[] = []
    for (const item of items) {
      for (const action of block.actions) {
        out.push({
          ref: actionRef(item.id, action.key),
          method: action.method,
          path: fillTemplate(action.endpoint, item.raw),
          ...(block.resourceType !== undefined ? { resourceType: block.resourceType, resourceId: item.id } : {}),
          ...(action.scopedPermission !== undefined ? { scopedPermission: action.scopedPermission } : {}),
        })
      }
    }
    return out
  }, [items, block.actions, block.resourceType])

  const batchKey = React.useMemo(
    () => checks.map((c) => `${c.method} ${c.path} ${c.scopedPermission ?? ""}`).join("|"),
    [checks],
  )

  const permitted = usePermittedActions(checks, batchKey)

  // After a mutation BOTH halves are stale: the queue and the permission answer.
  const refresh = state.status === "ready" || state.status === "empty" ? state.refresh : undefined
  const permittedRefresh =
    permitted.status === "ready" ? permitted.refresh : permitted.status === "error" ? permitted.retry : undefined
  const onDone = React.useCallback(() => {
    refresh?.()
    permittedRefresh?.()
  }, [refresh, permittedRefresh])

  if (state.status === "loading") return <Skeleton className="h-24 w-full" />
  if (state.status === "error")
    return <ErrorState title="Couldn't load this inbox" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "Nothing awaiting you"} />

  const paginate = block.pageSize !== undefined && block.pageSize > 0
  const pageSize = paginate ? block.pageSize! : items.length || 1
  const pageItems = paginate ? items.slice((page - 1) * pageSize, page * pageSize) : items

  return (
    <div className="space-y-2">
      {permitted.status === "error" && (
        <p className="text-xs text-muted-foreground">Actions unavailable — permissions could not be resolved.</p>
      )}
      <ul className="space-y-2">
        {pageItems.map((item, i) => {
          const allowedActions = block.actions.filter((action) => permitted.isAllowed(actionRef(item.id, action.key)))
          return (
            <li key={`${item.id}-${i}`} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-3">
              <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium">{item.title}</span>
                  {item.status !== "" && <Badge variant="secondary">{item.status}</Badge>}
                </div>
                {item.subtitle !== "" && <p className="text-xs text-muted-foreground">{item.subtitle}</p>}
                {item.timestamp !== "" && <p className="text-xs text-muted-foreground">{item.timestamp}</p>}
              </div>
              {allowedActions.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {allowedActions.map((action) => (
                    <InboxActionButton
                      key={action.key}
                      action={action}
                      path={fillTemplate(action.endpoint, item.raw)}
                      onDone={onDone}
                    />
                  ))}
                </div>
              )}
            </li>
          )
        })}
      </ul>
      {paginate && items.length > 0 && (
        <Pagination page={page} perPage={pageSize} total={items.length} onPageChange={setPage} />
      )}
    </div>
  )
}

/**
 * FlowRenderer — the desktop rendering of the `flow` graph block (#950).
 *
 * IT IS NOT A CANVAS, and that is a decision rather than a gap. The web
 * renderer draws the graph with `@xyflow/react`, which core already ships for
 * two admin screens; this template does not carry that dependency, and adding a
 * graph library to an offline desktop bundle to draw what is, in the
 * overwhelmingly common case, a straight line of steps is a poor trade.
 *
 * The block contract is platform-NEUTRAL: it says what the data means, and each
 * renderer maps that to its own platform. So the desktop maps it to the same
 * information without the picture — an ordered list of nodes, each naming the
 * nodes it leads to. Nothing is dropped: a reader can still follow the route,
 * see the branch, and open a node. Falling through to `UnsupportedBlock`
 * instead would have shown them none of it.
 *
 * The derivation below mirrors `web/components/plugin/blocks/flow-model.ts`
 * exactly — the ceiling applied to nodes in payload order before any edge is
 * derived, references to unknown or truncated ids dropped rather than
 * materialised, list-valued edge fields expanded, and the truncation announced.
 * Those are contract behaviour, not rendering, so the two must agree; #847 is
 * what a silent divergence between these two files costs.
 */
function FlowRenderer({ block }: { block: FlowBlock }) {
  const { openTarget } = React.useContext(MasterDetailContext)
  const source = useEffectiveSource(block.source, block.params)
  const state = usePluginData<Record<string, unknown>[]>(source, (data) =>
    Array.isArray(data) && data.length > 0 ? data : null,
  )
  useRefetchOnSignal(state)

  if (state.status === "loading") return <Skeleton className="h-24 w-full" />
  if (state.status === "error")
    return <ErrorState title="Couldn't load this diagram" action={<Button onClick={state.retry}>Retry</Button>} />
  if (state.status === "empty") return <EmptyState title={block.emptyText ?? "Nothing to diagram yet"} />

  const refresh = state.refresh
  const declared = block.maxNodes !== undefined && block.maxNodes > 0 ? block.maxNodes : FLOW_MAX_NODES
  const ceiling = Math.min(declared, FLOW_MAX_NODES)

  const seen = new Set<string>()
  const kept: { id: string; row: Record<string, unknown> }[] = []
  let total = 0
  for (const row of state.data) {
    const id = String(row[block.nodeIdField] ?? "")
    if (id === "" || seen.has(id)) continue
    seen.add(id)
    total++
    if (kept.length < ceiling) kept.push({ id, row })
  }

  if (kept.length === 0) return <EmptyState title={block.emptyText ?? "Nothing to diagram yet"} />

  const keptIds = new Set(kept.map((n) => n.id))
  const labelOf = new Map(kept.map((n) => [n.id, String(n.row[block.nodeLabelField] ?? "")]))
  const successors = new Map<string, string[]>(kept.map((n) => [n.id, []]))
  const link = (from: string, to: string) => {
    if (from === to || !keptIds.has(from) || !keptIds.has(to)) return
    const list = successors.get(from)!
    if (!list.includes(to)) list.push(to)
  }
  // A LIST is how one step branches to several; anything else is one id.
  const refs = (value: unknown): string[] =>
    (Array.isArray(value) ? value : [value])
      .filter((v) => v !== undefined && v !== null)
      .map(String)
      .filter((v) => v !== "")

  if (block.edgeFromField === undefined && block.edgeToField === undefined) {
    for (let i = 1; i < kept.length; i++) link(kept[i - 1].id, kept[i].id)
  } else {
    for (const node of kept) {
      if (block.edgeFromField !== undefined) for (const other of refs(node.row[block.edgeFromField])) link(other, node.id)
      if (block.edgeToField !== undefined) for (const other of refs(node.row[block.edgeToField])) link(node.id, other)
    }
  }

  // The node's own affordance is its FIRST `open` action, matching the web
  // renderer: on a canvas that is clicking the box, here it is the row's title.
  const primaryOpen = block.nodeActions?.find((a): a is Extract<RowAction, { open: string }> => "open" in a)

  return (
    <div className="space-y-2">
      {total > kept.length && (
        // Announced, never silent: a partial graph that looks complete is worse
        // than a crowded one, because a reader cannot see an absence.
        <p className="text-xs text-muted-foreground">
          Showing the first {kept.length} of {total} nodes — the rest are not drawn.
        </p>
      )}
      <ol className="space-y-2">
        {kept.map((node) => {
          const next = successors.get(node.id) ?? []
          const title = labelOf.get(node.id) ?? ""
          return (
            <li key={node.id} className="rounded-lg border border-border bg-card p-3">
              {primaryOpen !== undefined ? (
                <button type="button" className="text-sm font-medium" onClick={() => openTarget(primaryOpen.open, node.row)}>
                  {title}
                </button>
              ) : (
                <p className="text-sm font-medium">{title}</p>
              )}
              {block.nodeSubtitleField !== undefined && (
                <p className="text-xs text-muted-foreground">{String(node.row[block.nodeSubtitleField] ?? "")}</p>
              )}
              {next.length > 0 && (
                <p className="mt-1 text-xs text-muted-foreground">
                  <span aria-hidden>&rarr; </span>
                  {next.map((id) => labelOf.get(id) ?? id).join(", ")}
                </p>
              )}
              {block.nodeActions && block.nodeActions.length > 0 && (
                <div className="mt-2">
                  <RowActions actions={block.nodeActions} row={node.row} onDone={refresh} />
                </div>
              )}
            </li>
          )
        })}
      </ol>
    </div>
  )
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

// ------------------------------------------------- OU scope picker (#868)

/**
 * The host's own OU endpoints. The twin of web's `OU_SOURCE`/`OU_TYPES_SOURCE`,
 * and the same paths on purpose: this block is the one leaf in the contract that
 * fetches without a `source`, because the hierarchy and its type vocabulary
 * belong to the PLATFORM rather than to any plugin. The request goes through the
 * ordinary `php_request` proxy, so which routes exist is the HOST's answer, not
 * this renderer's — an offline host that serves no organizational units answers
 * 404 and the control reports that it could not load them, with a retry. It is
 * the same code path as web, taking the branch its environment produces, rather
 * than a hard-coded "not here" that would rot the day a host does serve them.
 */
const OU_SOURCE = "/api/v1/ous"
const OU_TYPES_SOURCE = "/api/v1/ou-types"

/** The sentinel option value for "no anchor" / "any kind" — Radix refuses "". */
const OU_ANY = "__any__"

/** U+2007 FIGURE SPACE: a fixed-width blank used to indent the tree in a flat list. */
const FIGURE_SPACE = "\u2007"

interface OuRow {
  id: number
  name: string
  parent_id: number | null
}

/** The permitted scopes for a block, defaulting to all three in canonical order. */
function effectiveScopes(block: OuScopePickerBlock): OuScopeKind[] {
  const declared = block.scopes
  if (!Array.isArray(declared) || declared.length === 0) return [...OU_SCOPE_KINDS]
  const valid = declared.filter((s): s is OuScopeKind => OU_SCOPE_KINDS.includes(s))
  return valid.length > 0 ? valid : [...OU_SCOPE_KINDS]
}

/**
 * The rule the control shows before the user has touched it. NOT seeded into the
 * form value map — `collectDefaults` deliberately seeds only what an input
 * declares a default for, so an untouched picker contributes nothing to the
 * payload and a form editing a stored rule never blanks it (#847's lesson,
 * applied ahead of time here rather than after).
 */
function emptyRule(block: OuScopePickerBlock): OuScopeValue {
  const scopes = effectiveScopes(block)
  const anchorless = scopes.find((s) => s !== "unit")
  return { unit: null, scope: anchorless ?? scopes[0], type: block.memberType ?? null }
}

/** Whether a stored form value is an OU scope rule. Mirrors web's `isOuScopeValue`. */
function isOuScopeValue(value: unknown): value is OuScopeValue {
  return (
    typeof value === "object" &&
    value !== null &&
    !Array.isArray(value) &&
    typeof (value as OuScopeValue).scope === "string" &&
    (OU_SCOPE_KINDS as readonly string[]).includes((value as OuScopeValue).scope)
  )
}

/** Coerce one `/api/v1/ous` row into the three fields the picker reads. */
function toOuRow(raw: Record<string, unknown>): OuRow | null {
  const id = Number(raw.id)
  if (!Number.isFinite(id)) return null
  const parent = raw.parent_id
  return {
    id,
    name: raw.name === undefined || raw.name === null ? String(id) : String(raw.name),
    parent_id: parent === undefined || parent === null ? null : Number(parent),
  }
}

/**
 * Order the flat OU list as a depth-first walk of the tree, carrying each row's
 * depth. Cycle-safe and loss-free: a row whose parent is absent from the list
 * becomes a root rather than disappearing, because a unit the operator can see
 * elsewhere but not here reads as missing data. Mirrors web's `orderOuRows`.
 */
function orderOuRows(rows: OuRow[]): { row: OuRow; depth: number }[] {
  const childrenOf = new Map<number | null, OuRow[]>()
  const present = new Set(rows.map((r) => r.id))
  for (const row of rows) {
    const key = row.parent_id !== null && present.has(row.parent_id) ? row.parent_id : null
    const bucket = childrenOf.get(key)
    if (bucket) bucket.push(row)
    else childrenOf.set(key, [row])
  }
  for (const bucket of childrenOf.values()) {
    bucket.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" }))
  }

  const out: { row: OuRow; depth: number }[] = []
  const visited = new Set<number>()
  const walk = (parent: number | null, depth: number): void => {
    for (const row of childrenOf.get(parent) ?? []) {
      if (visited.has(row.id)) continue
      visited.add(row.id)
      out.push({ row, depth })
      walk(row.id, depth + 1)
    }
  }
  walk(null, 0)
  for (const row of rows) {
    if (!visited.has(row.id)) {
      visited.add(row.id)
      out.push({ row, depth: 0 })
    }
  }
  return out
}

/**
 * The kind filter. Its own component so the vocabulary fetch is CONDITIONAL on
 * being rendered — a block with a pinned `memberType` shows no kind control and
 * must cost no request, and a hook cannot be run conditionally. Same split as
 * web's `OuKindSelect`.
 */
function OuKindSelect({ value, onChange }: { value: string | null; onChange: (next: string | null) => void }) {
  const types = usePluginData<Record<string, unknown>[]>(OU_TYPES_SOURCE, (data) => (Array.isArray(data) ? data : null))
  const options = types.status === "ready" ? types.data : []
  return (
    <Select value={value ?? OU_ANY} onValueChange={(v) => onChange(v === OU_ANY ? null : v)} disabled={types.status === "loading"}>
      <SelectTrigger aria-label="Kind">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {/* Always offered: a host with no adopted vocabulary still has a usable
            picker, and `type: null` is the correct rule for "whatever kind". */}
        <SelectItem value={OU_ANY}>Any kind</SelectItem>
        {options.map((raw, i) => {
          const key = raw.key
          if (typeof key !== "string" || key === "") return null
          return (
            <SelectItem key={`${key}-${i}`} value={key}>
              {raw.label === undefined || raw.label === null ? key : String(raw.label)}
            </SelectItem>
          )
        })}
      </SelectContent>
    </Select>
  )
}

/**
 * OuScopePickerRenderer — three controls over ONE value, the desktop twin of
 * web's `OuScopePickerField`.
 *
 * The invariant that makes the value shape trustworthy is the same on both
 * hosts: every control writes the WHOLE rule (`{unit, scope, type}`), composed
 * from the current one, never a partial patch — so no code path can persist a
 * rule missing its `scope`, the one field a consumer cannot recover by guessing.
 * `unit` scope and a kind filter are mutually exclusive by construction.
 */
function OuScopePickerRenderer({ block, form }: { block: OuScopePickerBlock; form: FormScope }) {
  // `anchorType` narrows the anchor list at the SOURCE: the host answers
  // `?type=` itself, so a large tenant does not ship every unit to filter most
  // of them away locally.
  const source =
    block.anchorType !== undefined && block.anchorType !== ""
      ? `${OU_SOURCE}?type=${encodeURIComponent(block.anchorType)}`
      : OU_SOURCE
  // usePluginData exhausts pagination (#870); a truncated unit list is what made
  // this block unbuildable before.
  const units = usePluginData<Record<string, unknown>[]>(source, (data) => (Array.isArray(data) ? data : null))

  const scopes = effectiveScopes(block)
  const stored = form.values[block.name]
  const rule: OuScopeValue = isOuScopeValue(stored) ? stored : emptyRule(block)
  const kindsPinned = block.memberType !== undefined && block.memberType !== ""

  const write = (next: OuScopeValue): void => {
    let scope = next.scope
    // "This unit" with no unit is not a rule — dropping the anchor moves to the
    // next permitted scope.
    if (next.unit === null && scope === "unit") {
      scope = scopes.find((s) => s !== "unit") ?? "unit"
    }
    // A kind filter over the single unit the user just picked can only subtract it.
    const type = scope === "unit" ? null : (block.memberType ?? next.type)
    form.setValue(block.name, { unit: next.unit, scope, type })
  }

  if (units.status === "error") {
    return (
      <div className="space-y-1">
        <span className="text-sm font-medium">{block.label}</span>
        <ErrorState
          title="Couldn't load organizational units"
          action={<Button onClick={units.retry}>Retry</Button>}
        />
      </div>
    )
  }

  const orderedUnits =
    units.status === "ready"
      ? orderOuRows(
          units.data.flatMap((raw) => {
            const row = toOuRow(raw)
            return row === null ? [] : [row]
          }),
        )
      : []
  // `scope: 'unit'` is only offerable once an anchor exists — (null, unit) is the
  // row of the resolution table that is never produced.
  const offerableScopes = rule.unit === null ? scopes.filter((s) => s !== "unit") : scopes
  const scopeLabels: Record<OuScopeKind, string> = {
    // The disambiguation lives in the option text, where the choice is made.
    unit: "This unit only",
    subtree: "This unit and everything below it",
    children: "Direct children only",
  }

  // A GROUP, not a single labelled input: the rule is built from up to three
  // controls, so there is no one element a `<label>` could wrap. `aria-labelledby`
  // names the whole group and each control carries its own aria-label underneath
  // it — the same construct web uses.
  const groupLabelId = `block-input-${block.name}-label`

  return (
    <div className="space-y-2" role="group" aria-labelledby={groupLabelId}>
      <span id={groupLabelId} className="text-sm font-medium">
        {block.label}
        {block.required ? " *" : ""}
      </span>

      <Select
        value={rule.unit === null ? OU_ANY : String(rule.unit)}
        onValueChange={(v) => write({ ...rule, unit: v === OU_ANY ? null : Number(v) })}
        disabled={units.status === "loading"}
      >
        <SelectTrigger aria-label={block.label}>
          <SelectValue placeholder={units.status === "loading" ? "Loading…" : (block.placeholder ?? "Select…")} />
        </SelectTrigger>
        <SelectContent>
          {block.required !== true && <SelectItem value={OU_ANY}>All organizational units</SelectItem>}
          {orderedUnits.map(({ row, depth }) => (
            <SelectItem key={row.id} value={String(row.id)}>
              {FIGURE_SPACE.repeat(depth * 2)}
              {row.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {offerableScopes.length > 1 && (
        <Select value={rule.scope} onValueChange={(v) => write({ ...rule, scope: v as OuScopeKind })}>
          <SelectTrigger aria-label="Scope">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {offerableScopes.map((scope) => (
              <SelectItem key={scope} value={scope}>
                {scopeLabels[scope]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}

      {!kindsPinned && rule.scope !== "unit" && (
        <OuKindSelect value={rule.type} onChange={(next) => write({ ...rule, type: next })} />
      )}
    </div>
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
  "ouScopePicker",
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
    // #909: BOTH child lists. An `accessGate` carries two, and a walk that knew
    // only `children` would seed defaults for the permitted rendering and not
    // for the refused one — so the same field name would be in the value map or
    // absent from it depending on which branch the author put it in, which is
    // not a distinction anyone declared. Hidden inputs staying in the value map
    // is the standing convention for `visibleWhen` (the server re-validates and
    // is authoritative over what it accepts); this keeps the two slots equal to
    // each other rather than inventing a third rule for one of them.
    for (const slot of ["children", "otherwise"] as const) {
      const nested = (block as { children?: unknown; otherwise?: unknown })[slot]
      if (Array.isArray(nested)) inputs.push(...collectFormInputs(nested as Block[]))
    }
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
    case "ouScopePicker":
      return <OuScopePickerRenderer block={block} form={form} />
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
