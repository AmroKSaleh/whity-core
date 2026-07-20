"use client"

import * as React from "react"
import {
  type ColumnDef,
  type ColumnFiltersState,
  type PaginationState,
  type SortingState,
  type VisibilityState,
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
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
  className?: string
}

function isServerPagination(
  p: DataTableServerPagination | { pageSize: number }
): p is DataTableServerPagination {
  return "onPaginationChange" in p
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
  enableColumnVisibility = false,
  enableColumnResizing = false,
  pagination,
  className,
}: DataTableProps<TData>) {
  const [sorting, setSorting] = React.useState<SortingState>([])
  const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([])
  const [globalFilter, setGlobalFilter] = React.useState("")
  const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>({})

  const serverMode = pagination != null && isServerPagination(pagination)
  const [clientPagination, setClientPagination] = React.useState<PaginationState>({
    pageIndex: 0,
    pageSize: pagination && !serverMode ? pagination.pageSize : 10,
  })

  const columnDefs = React.useMemo<ColumnDef<TData, unknown>[]>(() => {
    const defs: ColumnDef<TData, unknown>[] = columns.map((column) => {
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
        header: "Actions",
        cell: (info) => rowActions(info.row.original),
        enableSorting: false,
        enableColumnFilter: false,
        enableHiding: false,
      })
    }
    return defs
  }, [columns, rowActions])

  const table = useReactTable({
    data,
    columns: columnDefs,
    getRowId: getRowId
      ? (row, index) => getRowId(row, index)
      : undefined,
    state: {
      sorting,
      columnFilters,
      globalFilter: enableGlobalFilter ? globalFilter : undefined,
      columnVisibility,
      pagination: serverMode
        ? { pageIndex: pagination.pageIndex, pageSize: pagination.pageSize }
        : pagination
          ? clientPagination
          : undefined,
    },
    onSortingChange: setSorting,
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
    pageCount: serverMode ? pagination.pageCount : undefined,
    columnResizeMode: "onChange",
    enableColumnResizing,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: pagination && !serverMode ? getPaginationRowModel() : undefined,
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
        <Table>
          <TableHeader>
            <TableRow>
              {columns.map((column, index) => (
                <TableHead key={column.id ?? column.accessorKey ?? index}>
                  {column.header}
                </TableHead>
              ))}
              {rowActions && <TableHead>Actions</TableHead>}
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
      {(enableGlobalFilter || enableColumnVisibility) && (
        <div className="flex items-center justify-between gap-2">
          {enableGlobalFilter ? (
            <div className="relative w-full max-w-xs">
              <IconSearch className="pointer-events-none absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={globalFilter}
                onChange={(event) => setGlobalFilter(event.target.value)}
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
                  Columns
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
        <EmptyState title="No data available" {...emptyState} />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border">
          <Table style={{ width: table.getCenterTotalSize() }}>
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
                            placeholder="Filter…"
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
          page={table.getState().pagination.pageIndex + 1}
          perPage={table.getState().pagination.pageSize}
          total={serverMode ? pagination.total : table.getFilteredRowModel().rows.length}
          onPageChange={(nextPage) => table.setPageIndex(nextPage - 1)}
        />
      )}
    </div>
  )
}
