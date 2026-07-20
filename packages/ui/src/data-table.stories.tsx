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
