import { useState, type ReactElement } from "react"
import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconDatabaseOff } from "@tabler/icons-react"

import { Button } from "./button"
import { Badge } from "./badge"
import { DataTable, type DataTableColumn, type DataTableProps } from "./data-table"

interface UserRow {
  id: number
  name: string
  email: string
  role: string
  createdAt: string
}

const columns: DataTableColumn<UserRow>[] = [
  { accessorKey: "name", header: "Name", enableSorting: true, enableColumnFilter: true },
  { accessorKey: "email", header: "Email", enableSorting: true, enableColumnFilter: true },
  { accessorKey: "role", header: "Role", enableSorting: true },
  { accessorKey: "createdAt", header: "Created" },
]

const rows: UserRow[] = [
  { id: 1, name: "Ada Lovelace", email: "ada@acme.test", role: "admin", createdAt: "2026-01-04" },
  { id: 2, name: "Alan Turing", email: "alan@acme.test", role: "editor", createdAt: "2026-02-11" },
  { id: 3, name: "Grace Hopper", email: "grace@acme.test", role: "viewer", createdAt: "2026-03-20" },
  { id: 4, name: "Margaret Hamilton", email: "margaret@acme.test", role: "editor", createdAt: "2026-04-02" },
  { id: 5, name: "Katherine Johnson", email: "katherine@acme.test", role: "viewer", createdAt: "2026-05-18" },
]

// DataTable is generic (`<T>`); casting `component` to a monomorphic UserRow
// signature makes Storybook's `args` infer as UserRow instead of `unknown`.
const meta = {
  title: "Primitives/DataTable",
  component: DataTable as (props: DataTableProps<UserRow>) => ReactElement,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<(props: DataTableProps<UserRow>) => ReactElement>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { columns, data: rows },
}

export const WithRowActions: Story = {
  args: {
    columns,
    data: rows,
    rowActions: () => (
      <div className="flex justify-end gap-2">
        <Button size="xs" variant="outline">Edit</Button>
        <Button size="xs" variant="destructive">Delete</Button>
      </div>
    ),
  },
}

export const WithCustomCells: Story = {
  args: {
    columns: [
      ...columns.slice(0, 2),
      {
        id: "role",
        header: "Role",
        cell: (row) => (
          <Badge variant={row.role === "admin" ? "default" : "secondary"}>{row.role}</Badge>
        ),
      },
    ],
    data: rows,
  },
}

export const WithFilteringAndSearch: Story = {
  args: {
    columns,
    data: rows,
    enableGlobalFilter: true,
    globalFilterPlaceholder: "Search users…",
  },
}

export const WithColumnVisibility: Story = {
  args: { columns, data: rows, enableColumnVisibility: true },
}

export const WithClientPagination: Story = {
  args: { columns, data: rows, pagination: { pageSize: 2 } },
}

function ServerPaginatedExample() {
  const pageSize = 2
  const pageCount = Math.ceil(rows.length / pageSize)
  const [pageIndex, setPageIndex] = useState(0)
  const page = rows.slice(pageIndex * pageSize, pageIndex * pageSize + pageSize)
  return (
    <DataTable
      columns={columns}
      data={page}
      pagination={{
        pageIndex,
        pageSize,
        pageCount,
        total: rows.length,
        onPaginationChange: (nextPageIndex) => setPageIndex(nextPageIndex),
      }}
    />
  )
}

export const WithServerPagination: Story = {
  args: { columns, data: rows },
  render: () => <ServerPaginatedExample />,
}

/**
 * The whole server-side story: page, sort and search all owned by the caller.
 *
 * The sorting and searching here are stand-ins for what a real caller's API
 * would do — the point of the story is that the TABLE does neither. It renders
 * the chevrons and the box, reports the clicks and the keystrokes, and shows
 * the rows in the order it was handed them.
 */
function ServerDrivenExample() {
  const pageSize = 2
  const [pageIndex, setPageIndex] = useState(0)
  const [sortKey, setSortKey] = useState<string | null>("name")
  const [direction, setDirection] = useState<"asc" | "desc">("asc")
  const [search, setSearch] = useState("")

  // Stands in for the endpoint. A real caller re-fetches instead.
  const matched = search
    ? rows.filter((row) =>
        `${row.name} ${row.email} ${row.role}`.toLowerCase().includes(search.toLowerCase())
      )
    : rows
  const ordered = sortKey
    ? [...matched].sort((a, b) => {
        const [x, y] = [a, b].map((row) => String(row[sortKey as keyof UserRow]))
        return direction === "asc" ? x.localeCompare(y) : y.localeCompare(x)
      })
    : matched
  const page = ordered.slice(pageIndex * pageSize, pageIndex * pageSize + pageSize)

  return (
    <DataTable
      columns={columns}
      data={page}
      pagination={{
        pageIndex,
        pageSize,
        pageCount: Math.max(1, Math.ceil(ordered.length / pageSize)),
        total: ordered.length,
        onPaginationChange: (nextPageIndex) => setPageIndex(nextPageIndex),
      }}
      sorting={{
        sortKey,
        direction,
        onSortingChange: (nextKey, nextDirection) => {
          setSortKey(nextKey)
          setDirection(nextDirection)
          // Changing the sort invalidates the offset — the caller's job.
          setPageIndex(0)
        },
      }}
      search={{
        value: search,
        onSearchChange: (value) => {
          setSearch(value)
          setPageIndex(0)
        },
      }}
    />
  )
}

export const WithServerSortingAndSearch: Story = {
  args: { columns, data: rows },
  render: () => <ServerDrivenExample />,
}

export const Loading: Story = {
  args: { columns, data: [], isLoading: true },
}

export const Empty: Story = {
  args: {
    columns,
    data: [],
    emptyState: {
      icon: <IconDatabaseOff />,
      title: "No users yet",
      description: "Invite your first teammate to get started.",
      action: <Button size="sm">Invite user</Button>,
    },
  },
}
