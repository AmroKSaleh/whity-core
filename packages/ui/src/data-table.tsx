"use client"

import * as React from "react"
import {
  type ColumnDef,
  type ColumnFiltersState,
  type ColumnVisibilityState,
  type PaginationState,
  type SortingState,
  columnFilteringFeature,
  columnResizingFeature,
  columnSizingFeature,
  columnVisibilityFeature,
  createCoreRowModel,
  createFilteredRowModel,
  createPaginatedRowModel,
  createSortedRowModel,
  filterFn_arrIncludes,
  filterFn_equals,
  filterFn_inDateRange,
  filterFn_inNumberRange,
  filterFn_includesString,
  filterFn_weakEquals,
  flexRender,
  globalFilteringFeature,
  rowPaginationFeature,
  rowSortingFeature,
  sortFn_alphanumeric,
  sortFn_basic,
  sortFn_datetime,
  sortFn_text,
  useTable,
} from "@tanstack/react-table"
import {
  IconArrowsSort,
  IconChevronDown,
  IconChevronUp,
  IconLayoutColumns,
  IconSearch,
} from "@tabler/icons-react"

import { cn } from "./utils"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "./table"
import { Button } from "./button"
import { Input } from "./input"
import { Pagination } from "./pagination"
import { Skeleton } from "./skeleton"
import { EmptyState, ErrorState, type EmptyStateProps } from "./empty-state"
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "./dropdown-menu"

/**
 * One column definition. A thin, purpose-shaped wrapper over TanStack
 * Table's `ColumnDef` — callers describe WHAT a column is (key/header/cell),
 * this component wires up HOW it sorts/filters/resizes/hides.
 */
export interface DataTableColumn<TData> {
  /** Unique column id. Defaults to `accessorKey` when omitted. */
  id?: string
  /** Dot-free top-level key read from each row when `cell` is omitted. */
  accessorKey?: Extract<keyof TData, string>
  header: React.ReactNode
  /** Custom cell renderer — receives the full row, returns any node. */
  cell?: (row: TData) => React.ReactNode
  enableSorting?: boolean
  enableColumnFilter?: boolean
  /** Whether this column can be hidden via the column-visibility menu. Default true. */
  enableHiding?: boolean
  /** Initial/fixed width in px, also used as the resize starting point. */
  size?: number
  className?: string
}

/** Server-driven pagination: caller owns the page/pageSize/total, we just render controls. */
export interface DataTableServerPagination {
  pageIndex: number
  pageSize: number
  pageCount: number
  /** Total row count across all pages — drives the "N entries" label. */
  total: number
  onPaginationChange: (pageIndex: number, pageSize: number) => void
}

/**
 * Server-driven sorting: the caller owns the sort column and direction, we
 * render the header affordances and call back. The same shape of bargain as
 * {@link DataTableServerPagination}.
 *
 * WHY THIS HAS TO EXIST. Without it, a table in server-pagination mode still
 * sorts CLIENT-side, and the registered `sortedRowModel` sorts THE ROWS IT WAS
 * HANDED — the twenty five on screen. Clicking "Name" then reorders one page and
 * presents the result as a sorted list; page 2 re-sorts a different twenty
 * five. Nothing errors and nothing looks wrong, which is the whole problem:
 * the reader has no way to tell a sorted list from a sorted page. Screens hit
 * this already and worked around it by making their columns non-sortable and
 * moving sort into their own toolbar (see the document library's
 * `LibraryToolbar`), because the alternative was shipping the untruth.
 */
export interface DataTableServerSorting {
  /** Column id currently sorted, or null for "no column chosen". */
  sortKey: string | null
  direction: "asc" | "desc"
  /**
   * Called with the NEXT sort when the user clicks a sortable header.
   *
   * `sortKey` is null when a column is cycled past descending back to
   * unsorted (TanStack's default asc → desc → none cycle). `direction` is then
   * not meaningful — send no `dir` upstream in that case, so the endpoint's own
   * default ordering applies rather than one this component invented.
   *
   * Changing the sort invalidates the current page offset. Resetting to page 1
   * is the caller's to do, since the caller owns the pagination state.
   */
  onSortingChange: (sortKey: string | null, direction: "asc" | "desc") => void
}

/**
 * Quiet period before a keystroke becomes a request, in ms.
 *
 * Exported so a caller or a test can reach the default by name instead of
 * re-stating the number, and overridable per table via
 * {@link DataTableServerSearch.debounceMs} — typing "engineering" is twelve
 * requests without it.
 */
export const DATA_TABLE_SEARCH_DEBOUNCE_MS = 300

/**
 * Server-driven global search: the caller owns the term, we own the input.
 *
 * Same hazard as {@link DataTableServerSorting}: a client-side global filter
 * over a server-paginated table filters the current page, so a search that
 * matches nothing on page 1 reports "no results" while the match sits on
 * page 4.
 */
export interface DataTableServerSearch {
  /**
   * The term the caller has APPLIED. This is not necessarily what is in the
   * box: the input keeps its own draft so typing stays responsive while the
   * request is debounced. An external change to this value (a "clear search"
   * button elsewhere on the page) is adopted into the box; the echo of our own
   * callback is not, because adopting it would overwrite whatever was typed
   * while the request was in flight.
   */
  value: string
  onSearchChange: (value: string) => void
  /**
   * Quiet period after typing stops, in ms. Defaults to
   * {@link DATA_TABLE_SEARCH_DEBOUNCE_MS}. Pass 0 to call back on every
   * keystroke (the caller is debouncing, or a test wants determinism).
   */
  debounceMs?: number
}

export interface DataTableProps<TData> {
  columns: DataTableColumn<TData>[]
  data: TData[]
  getRowId?: (row: TData, index: number) => string
  /** Trailing actions cell render-prop, matching the previous DataTable's convention. */
  rowActions?: (row: TData) => React.ReactNode
  isLoading?: boolean
  /** Replaces the ENTIRE table (chrome included) — e.g. a 403 Access Denied screen. */
  overrideContent?: React.ReactNode
  emptyState?: Omit<EmptyStateProps, "variant">
  errorState?: Omit<EmptyStateProps, "variant">
  /** Free-text search across every column's string value. Off by default. */
  enableGlobalFilter?: boolean
  globalFilterPlaceholder?: string
  /** Header text for the row-actions column. Defaults to "Actions". */
  rowActionsLabel?: string
  /**
   * Accessible name for the table element, applied in EVERY state that renders
   * a `table` — the loading skeleton and the loaded rows alike.
   *
   * A page with two DataTables gives assistive technology — and any test
   * selecting by role — two identical, unnamed `table` landmarks with no way to
   * tell them apart. Naming them is the a11y answer and it is what makes
   * `getByRole('table', { name })` a stable selector instead of a positional
   * guess that breaks the next time a panel is added.
   *
   * It was originally wired into the skeleton branch ONLY (#967). Both halves of
   * that sentence then failed: a screen reader met the LOADED users table — the
   * state anybody actually reads — as an unnamed twin of the invitations table,
   * and `getByRole('table', { name })` silently became an assertion about the
   * skeleton, satisfied only while the request was still in flight. It passed
   * when the backend was slow and failed when it was fast, which is why it read
   * as flakiness on unrelated PRs and cost three CI cycles before it was
   * diagnosed. Any new branch here that renders a `table` must name it too.
   */
  ariaLabel?: string
  /**
   * Title shown when there are no rows and the caller passed no `emptyState`.
   * `emptyState` still wins — this only replaces the built-in English default,
   * so a caller translating just this string does not have to reconstruct the
   * whole empty state.
   */
  emptyStateTitle?: string
  /** Placeholder for the per-column filter inputs. Defaults to "Filter…". */
  columnFilterPlaceholder?: string
  /** Text of the column-visibility menu trigger. Defaults to "Columns". */
  columnsMenuLabel?: string
  /**
   * Copy for the pagination controls this table renders for itself.
   *
   * Forwarded to <Pagination>. Without this the table's own footer — 'N
   * entries', 'page 2 of 7', the prev/next buttons — would be the one part
   * of a translated table still stuck in English, with no way for a caller
   * to reach it.
   */
  paginationLabels?: Pick<
    React.ComponentProps<typeof Pagination>,
    "entriesLabel" | "pageLabel" | "navLabel" | "previousLabel" | "nextLabel"
  >
  /** Show/hide-columns menu. Off by default. */
  enableColumnVisibility?: boolean
  /** Drag-resize column borders. Off by default. */
  enableColumnResizing?: boolean
  /**
   * Omit for no pagination. Pass `{ pageSize }` (a plain number of rows per
   * page) for automatic CLIENT-side pagination. Pass a
   * {@link DataTableServerPagination} object when the caller's API already
   * paginates server-side (manual mode — this component then just renders
   * controls and calls back, never re-slices `data` itself).
   */
  pagination?: DataTableServerPagination | { pageSize: number }
  /**
   * Omit to let this component sort the rows it holds (the default, and what
   * every existing caller gets). Pass a {@link DataTableServerSorting} object
   * when the SERVER sorts: the headers then reflect the caller's state and
   * report clicks, and the rows are rendered in the order they arrived.
   */
  sorting?: DataTableServerSorting
  /**
   * Omit to let `enableGlobalFilter` filter the rows it holds. Pass a
   * {@link DataTableServerSearch} object when the SERVER searches: the same
   * search box renders (passing this implies it, `enableGlobalFilter` or not)
   * and reports what is typed, and no row is filtered out locally.
   *
   * Per-column filters are deliberately LEFT ALONE by this — see the note on
   * `manualFiltering` at the `useTable` call.
   */
  search?: DataTableServerSearch
  className?: string
}

function isServerPagination(
  p: DataTableServerPagination | { pageSize: number }
): p is DataTableServerPagination {
  return "onPaginationChange" in p
}

/**
 * The TanStack v9 feature set this table runs on.
 *
 * v9 REPLACED THE v8 `getXRowModel()` OPTIONS WITH EXPLICIT REGISTRATION. In v8
 * a table got sorting because you passed `getSortedRowModel()`; in v9 it gets
 * sorting because `rowSortingFeature` is registered here and the matching
 * `sortedRowModel` factory sits beside it. Nothing is implicit, and a feature
 * left out of this object does not merely go unused — its options and its slice
 * of table state stop existing, and TypeScript says so at the call site.
 *
 * DECLARED AT MODULE SCOPE because the identity is read on every render and a
 * fresh object each time would rebuild the table's feature registry for no
 * reason. It carries no per-table state, so one shared value is correct.
 *
 * `columnMeta` is a TYPE-ONLY slot: the value is stripped at runtime and only
 * its type is used, which is how `meta: { className }` on a column definition
 * type-checks again. In v8 `meta` was loosely typed and ours assigned by luck;
 * v9 threads `ExtractColumnMeta<TFeatures>` through, so the shape has to be
 * declared once — here — rather than asserted at each of the 26 call sites.
 */
/**
 * The feature set's own type, and the row type v9 will accept.
 *
 * v9 constrains table data to `RowData` (`Record<string, any> | Array<any>`).
 * {@link DataTableProps} deliberately does NOT constrain `TData` — 26 call sites
 * pass plain interfaces, and adding the constraint to the public prop would push
 * this migration out into every one of them, which is exactly what this rewrite
 * exists to avoid. So the bridge is here, once, and the props contract is
 * unchanged.
 */
type TableFeatureSet = typeof tableFeatures
type TableRow<TData> = TData & Record<string, unknown>

const tableFeatures = {
  columnFilteringFeature,
  columnResizingFeature,
  columnSizingFeature,
  columnVisibilityFeature,
  globalFilteringFeature,
  rowPaginationFeature,
  rowSortingFeature,
  coreRowModel: createCoreRowModel(),
  filteredRowModel: createFilteredRowModel(),
  sortedRowModel: createSortedRowModel(),
  // Client-side paging is still a supported v9 row model — `createPaginatedRowModel`
  // is the rename of v8's `getPaginationRowModel`, not a removal. Registering it
  // unconditionally is safe: with `manualPagination` on, the server owns the
  // slice and this model is never consulted.
  paginatedRowModel: createPaginatedRowModel(),
  /**
   * THE FILTER AND SORT FUNCTIONS THE AUTO-RESOLVER CAN ASK FOR.
   *
   * v9 resolves an unconfigured column to a filter/sort function BY NAME and
   * looks that name up here. The two halves fail differently, and both quietly:
   *
   *  - an unregistered FILTER function returns `undefined`, and the column
   *    filter becomes a no-op. Typing in the filter box narrows nothing.
   *  - an unregistered SORT function falls back to `sortFn_basic`, so the
   *    column still sorts — just wrongly. `basic` compares with `<`, so
   *    "item 10" lands before "item 2" and case is handled differently from
   *    the `text`/`alphanumeric` functions v8 chose automatically.
   *
   * Neither raises anything outside a development console warning, which is why
   * these are listed explicitly rather than left to a default that no longer
   * exists. The six filter functions are exactly the ones the auto-resolver can
   * pick (string / number / boolean / array / date / fallback); registering the
   * whole exported registry instead would put every built-in in the bundle.
   */
  filterFns: {
    arrIncludes: filterFn_arrIncludes,
    equals: filterFn_equals,
    inDateRange: filterFn_inDateRange,
    inNumberRange: filterFn_inNumberRange,
    includesString: filterFn_includesString,
    weakEquals: filterFn_weakEquals,
  },
  sortFns: {
    alphanumeric: sortFn_alphanumeric,
    basic: sortFn_basic,
    datetime: sortFn_datetime,
    text: sortFn_text,
  },
  columnMeta: {} as { className?: string },
}

export function DataTable<TData>({
  columns,
  data,
  getRowId,
  rowActions,
  isLoading = false,
  overrideContent,
  emptyState,
  errorState,
  enableGlobalFilter = false,
  globalFilterPlaceholder = "Search…",
  rowActionsLabel = "Actions",
  ariaLabel,
  emptyStateTitle = "No data available",
  columnFilterPlaceholder = "Filter…",
  columnsMenuLabel = "Columns",
  paginationLabels,
  enableColumnVisibility = false,
  enableColumnResizing = false,
  pagination,
  sorting: serverSorting,
  search: serverSearch,
  className,
}: DataTableProps<TData>) {
  const [clientSorting, setClientSorting] = React.useState<SortingState>([])
  const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([])
  const [globalFilter, setGlobalFilter] = React.useState("")
  const [columnVisibility, setColumnVisibility] = React.useState<ColumnVisibilityState>({})

  const serverMode = pagination != null && isServerPagination(pagination)

  /**
   * The caller's sort, in the shape TanStack keeps it in. One column at a
   * time: the server contract this pairs with takes a single `sort` key, so
   * offering multi-sort in the UI would promise an ordering the request cannot
   * express.
   */
  const sortingState: SortingState = serverSorting
    ? serverSorting.sortKey
      ? [{ id: serverSorting.sortKey, desc: serverSorting.direction === "desc" }]
      : []
    : clientSorting
  const [clientPagination, setClientPagination] = React.useState<PaginationState>({
    pageIndex: 0,
    pageSize: pagination && !serverMode ? pagination.pageSize : 10,
  })

  // What is TYPED, which is not what has been SENT. The box must stay
  // responsive while the request is debounced, so it renders this draft rather
  // than `serverSearch.value`; a controlled input bound straight to a value
  // that only updates after the round trip drops characters typed in between.
  const appliedSearch = serverSearch?.value ?? ""
  const [searchDraft, setSearchDraft] = React.useState(appliedSearch)
  const searchTimer = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  // The last term we handed to `onSearchChange`. When the caller sets `value`
  // to exactly this, it is the ECHO of our own callback, not somebody else
  // changing the search, and adopting it would clobber the characters typed
  // between the timer firing and the caller re-rendering.
  const lastEmittedSearch = React.useRef<string | null>(null)

  const clearSearchTimer = () => {
    if (searchTimer.current !== null) {
      clearTimeout(searchTimer.current)
      searchTimer.current = null
    }
  }

  React.useEffect(() => {
    if (lastEmittedSearch.current === appliedSearch) return
    lastEmittedSearch.current = null
    // An external change wins over anything still pending — a "clear search"
    // button elsewhere on the page must not be undone a moment later by a
    // timer carrying the term it just cleared.
    clearSearchTimer()
    setSearchDraft(appliedSearch)
  }, [appliedSearch])

  // A pending timer outliving the component would call back into an unmounted
  // caller. Cancelling on unmount is the whole of it.
  React.useEffect(() => clearSearchTimer, [])

  const handleSearchInput = (next: string) => {
    if (!serverSearch) {
      setGlobalFilter(next)
      return
    }
    setSearchDraft(next)
    clearSearchTimer()
    const delay = serverSearch.debounceMs ?? DATA_TABLE_SEARCH_DEBOUNCE_MS
    if (delay <= 0) {
      lastEmittedSearch.current = next
      serverSearch.onSearchChange(next)
      return
    }
    searchTimer.current = setTimeout(() => {
      searchTimer.current = null
      lastEmittedSearch.current = next
      serverSearch.onSearchChange(next)
    }, delay)
  }

  /** Passing `search` implies the search box, whether or not the flag is set. */
  const showSearchInput = enableGlobalFilter || serverSearch != null

  const columnDefs = React.useMemo<ColumnDef<TableFeatureSet, TableRow<TData>, unknown>[]>(() => {
    const defs: ColumnDef<TableFeatureSet, TableRow<TData>, unknown>[] = columns.map((column) => {
      const id = column.id ?? column.accessorKey
      if (!id) {
        throw new Error("DataTable: every column needs an `id` or `accessorKey`")
      }
      return {
        id,
        accessorKey: column.accessorKey,
        header: column.header as string,
        cell: column.cell
          ? (info) => column.cell!(info.row.original)
          : (info) => {
              const value = column.accessorKey
                ? (info.row.original as Record<string, unknown>)[column.accessorKey]
                : undefined
              return value == null || value === "" ? (
                <span className="text-muted-foreground">—</span>
              ) : (
                String(value)
              )
            },
        enableSorting: column.enableSorting ?? false,
        enableColumnFilter: column.enableColumnFilter ?? false,
        enableHiding: column.enableHiding ?? true,
        size: column.size,
        meta: { className: column.className },
      }
    })
    if (rowActions) {
      defs.push({
        id: "__row-actions",
        header: rowActionsLabel,
        cell: (info) => rowActions(info.row.original),
        enableSorting: false,
        enableColumnFilter: false,
        enableHiding: false,
      })
    }
    return defs
  }, [columns, rowActions, rowActionsLabel])

  const table = useTable<TableFeatureSet, TableRow<TData>>({
    features: tableFeatures,
    data: data as TableRow<TData>[],
    columns: columnDefs,
    getRowId: getRowId
      ? (row, index) => getRowId(row, index)
      : undefined,
    state: {
      sorting: sortingState,
      columnFilters,
      // In server-search mode the term never enters table state, so there is
      // nothing here for TanStack to filter by. See `manualFiltering` below.
      globalFilter: enableGlobalFilter && !serverSearch ? globalFilter : undefined,
      columnVisibility,
      // NO PAGINATION MEANS ONE PAGE OF EVERYTHING, SAID EXPLICITLY.
      //
      // In v8 a table without pagination simply had no paginated row model, so
      // `getRowModel()` returned every row. In v9 the row model is registered
      // on the feature set for the whole component, so it applies here too and
      // an omitted `pagination` state falls back to the library default of ten
      // rows — silently hiding row 11 onward on every unpaginated table.
      //
      // `pageSize: Infinity` with `pageIndex: 0` is the documented escape:
      // `createPaginatedRowModel` skips slicing entirely for exactly that pair.
      // (`manualPagination` does NOT do this — that model never consults it.)
      pagination: serverMode
        ? { pageIndex: pagination.pageIndex, pageSize: pagination.pageSize }
        : pagination
          ? clientPagination
          : { pageIndex: 0, pageSize: Number.POSITIVE_INFINITY },
    },
    onSortingChange: serverSorting
      ? (updater) => {
          const next = typeof updater === "function" ? updater(sortingState) : updater
          // Single-column, so the head of the list IS the sort. An empty list
          // is the third click of asc → desc → none: no column chosen, and the
          // direction that goes with it is not meaningful — see the prop docs.
          const [next0] = next
          serverSorting.onSortingChange(
            next0 ? next0.id : null,
            next0?.desc ? "desc" : "asc"
          )
        }
      : setClientSorting,
    onColumnFiltersChange: setColumnFilters,
    onGlobalFilterChange: setGlobalFilter,
    onColumnVisibilityChange: setColumnVisibility,
    onPaginationChange: serverMode
      ? (updater) => {
          const next =
            typeof updater === "function"
              ? updater({ pageIndex: pagination.pageIndex, pageSize: pagination.pageSize })
              : updater
          pagination.onPaginationChange(next.pageIndex, next.pageSize)
        }
      : setClientPagination,
    manualPagination: serverMode,
    /**
     * THIS is what stops the reordering, not the absence of a row model.
     *
     * `sortedRowModel` stays registered on the module-scope `tableFeatures` —
     * it is shared by every table this component renders, so it cannot be
     * withheld per-instance the way v8's per-call `getSortedRowModel()` could.
     * The flag is the per-instance control: v9 gates the pipeline in
     * `table_getSortedRowModel` (`core/row-models/coreRowModelsFeature.utils.js`,
     * table-core 9.2.4), which returns the PRE-sorted model when
     * `manualSorting` is set and never consults the comparator.
     *
     * Drop this flag and the headers keep working and the callback keeps
     * firing, and the rows ALSO get re-sorted locally on top of the server's
     * order — a sort that is nearly right, which is the hardest kind to notice.
     */
    manualSorting: serverSorting != null,
    /*
     * NO `manualFiltering`, deliberately, and it is not an oversight.
     *
     * Re-checked against v9, because v9 splits `columnFilteringFeature` and
     * `globalFilteringFeature` into separate features and the split looks like
     * it should have brought a global-only flag with it. It did not.
     * `manualFiltering` is still the only one, it is still declared on
     * `columnFilteringFeature`, and `globalFilteringFeature` has no manual
     * option at all. The gate is one stage of the shared row-model pipeline —
     * `table_getFilteredRowModel`, documented as "the row model after column
     * AND global filtering" — so setting the flag would take the per-column
     * filter inputs down with the global one, exactly as in v8. Those are used
     * by around eighteen columns across the admin screens, the users table
     * included — the first screen expected to adopt server search — and they
     * would keep rendering and keep accepting text while filtering nothing.
     *
     * Keeping the search term out of `state.globalFilter` (above) achieves what
     * the flag would have achieved for search, and nothing more:
     * `createFilteredRowModel` treats `undefined` as "no global filter" and
     * applies none, while column filters go on working client-side.
     * Server-side column filters are a contract that does not exist yet; when
     * it does, this is where it lands.
     */
    pageCount: serverMode ? pagination.pageCount : undefined,
    columnResizeMode: "onChange",
    enableColumnResizing,
  })

  if (overrideContent) {
    return <>{overrideContent}</>
  }

  const filterableColumns = table
    .getAllLeafColumns()
    .filter((column) => column.getCanFilter())
  const hideableColumns = table
    .getAllLeafColumns()
    .filter((column) => column.getCanHide())

  if (isLoading) {
    const visibleCount = table.getVisibleLeafColumns().length
    return (
      <div className={cn("rounded-lg border border-border", className)}>
        {/* `aria-busy` marks this as the PLACEHOLDER, not the data.
            It carries the same `aria-label` as the real table — deliberately,
            so assistive technology names the region consistently while it
            loads — and the consequence is that `getByRole('table', {name})`
            matches this skeleton exactly as it matches a populated table. A
            test written against that selector can be satisfied by five rows of
            grey bars: it passes when the data arrived, when the folder is
            empty and the assertion merely won the race, and when the request
            failed and the retry is in flight. One shipped that way (#1006's
            "the pane is never simply blank" asserted `toBeVisible()` and
            `not.toBeEmpty()` and both were true of THIS markup), passed
            locally, and failed in CI on the one stack where the fetch resolved
            first — which is the only run that was telling the truth.
            `aria-busy` is the standard signal for it and is additive, so no
            existing selector changes. Prefer waiting on a terminal state
            (a row, or the empty state's own text) over waiting on the table. */}
        <Table aria-label={ariaLabel} aria-busy="true">
          <TableHeader>
            <TableRow>
              {columns.map((column, index) => (
                <TableHead key={column.id ?? column.accessorKey ?? index}>
                  {column.header}
                </TableHead>
              ))}
              {rowActions && <TableHead>{rowActionsLabel}</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {Array.from({ length: 5 }).map((_, rowIndex) => (
              <TableRow key={rowIndex}>
                {Array.from({ length: visibleCount }).map((__, colIndex) => (
                  <TableCell key={colIndex}>
                    <Skeleton className="h-4 w-3/4" />
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
        <span className="sr-only" role="status" aria-live="polite">
          Loading…
        </span>
      </div>
    )
  }

  const rows = table.getRowModel().rows

  return (
    <div className={cn("flex flex-col gap-3", className)}>
      {(showSearchInput || enableColumnVisibility) && (
        <div className="flex items-center justify-between gap-2">
          {showSearchInput ? (
            <div className="relative w-full max-w-xs">
              <IconSearch className="pointer-events-none absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={serverSearch ? searchDraft : globalFilter}
                onChange={(event) => handleSearchInput(event.target.value)}
                placeholder={globalFilterPlaceholder}
                className="ps-7"
              />
            </div>
          ) : (
            <div />
          )}
          {enableColumnVisibility && hideableColumns.length > 0 && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="xs">
                  <IconLayoutColumns className="size-3.5" />
                  {columnsMenuLabel}
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {hideableColumns.map((column) => (
                  <DropdownMenuCheckboxItem
                    key={column.id}
                    checked={column.getIsVisible()}
                    onCheckedChange={(value) => column.toggleVisibility(!!value)}
                    onSelect={(event) => event.preventDefault()}
                  >
                    {typeof column.columnDef.header === "string"
                      ? column.columnDef.header
                      : column.id}
                  </DropdownMenuCheckboxItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>
      )}

      {errorState ? (
        <ErrorState {...errorState} />
      ) : rows.length === 0 ? (
        <EmptyState title={emptyStateTitle} {...emptyState} />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border">
          <Table
            aria-label={ariaLabel}
            style={enableColumnResizing ? { width: table.getCenterTotalSize() } : undefined}
          >
            <TableHeader>
              {table.getHeaderGroups().map((headerGroup) => (
                <TableRow key={headerGroup.id}>
                  {headerGroup.headers.map((header) => (
                    <TableHead
                      key={header.id}
                      style={
                        enableColumnResizing ? { width: header.getSize(), position: "relative" } : undefined
                      }
                      className={cn(
                        header.column.getCanSort() && "cursor-pointer select-none hover:bg-muted/60"
                      )}
                      onClick={header.column.getToggleSortingHandler()}
                    >
                      <div className="flex items-center gap-1">
                        {flexRender(header.column.columnDef.header, header.getContext())}
                        {header.column.getCanSort() &&
                          (header.column.getIsSorted() === "asc" ? (
                            <IconChevronUp className="size-3.5" />
                          ) : header.column.getIsSorted() === "desc" ? (
                            <IconChevronDown className="size-3.5" />
                          ) : (
                            <IconArrowsSort className="size-3.5 opacity-40" />
                          ))}
                      </div>
                      {enableColumnResizing && header.column.getCanResize() && (
                        <div
                          onMouseDown={header.getResizeHandler()}
                          onTouchStart={header.getResizeHandler()}
                          className="absolute end-0 top-0 h-full w-1 cursor-col-resize touch-none select-none bg-border/0 hover:bg-ring/50"
                        />
                      )}
                    </TableHead>
                  ))}
                </TableRow>
              ))}
              {filterableColumns.length > 0 && (
                <TableRow>
                  {table.getHeaderGroups()[0]?.headers.map((header) => {
                    const canFilter = header.column.getCanFilter()
                    const label =
                      typeof header.column.columnDef.header === "string"
                        ? header.column.columnDef.header
                        : header.column.id
                    return (
                      <TableHead key={`filter-${header.id}`} className="py-1.5">
                        {canFilter ? (
                          <Input
                            value={(header.column.getFilterValue() as string) ?? ""}
                            onChange={(event) =>
                              header.column.setFilterValue(event.target.value)
                            }
                            placeholder={columnFilterPlaceholder}
                            aria-label={`Filter ${label}`}
                            className="h-6 text-xs font-normal normal-case tracking-normal"
                          />
                        ) : null}
                      </TableHead>
                    )
                  })}
                </TableRow>
              )}
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.id}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell
                      key={cell.id}
                      className={(cell.column.columnDef.meta as { className?: string } | undefined)?.className}
                      style={enableColumnResizing ? { width: cell.column.getSize() } : undefined}
                    >
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {pagination && rows.length > 0 && (
        <Pagination
          page={table.state.pagination.pageIndex + 1}
          perPage={table.state.pagination.pageSize}
          total={serverMode ? pagination.total : table.getFilteredRowModel().rows.length}
          onPageChange={(nextPage) => table.setPageIndex(nextPage - 1)}
          {...paginationLabels}
        />
      )}
    </div>
  )
}
