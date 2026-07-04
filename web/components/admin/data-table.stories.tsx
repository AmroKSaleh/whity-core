import type { ReactElement } from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import { IconDatabaseOff } from "@tabler/icons-react"
import { Button } from "@amroksaleh/ui/button"
import { Badge } from "@amroksaleh/ui/badge"

import { DataTable, type Column, type DataTableProps } from "./data-table"

interface UserRow {
  id: number
  name: string
  email: string
  role: string
}

const columns: Column<UserRow>[] = [
  { key: "name", label: "Name", sortable: true },
  { key: "email", label: "Email", sortable: true },
  { key: "role", label: "Role", sortable: true },
]

const rows: UserRow[] = [
  { id: 1, name: "Ada Lovelace", email: "ada@acme.test", role: "admin" },
  { id: 2, name: "Alan Turing", email: "alan@acme.test", role: "editor" },
  { id: 3, name: "Grace Hopper", email: "grace@acme.test", role: "viewer" },
]

// DataTable is generic (`<T>`); Storybook would erase the type param to its
// `{ id }` constraint for `args`. Casting `component` to a monomorphic UserRow
// signature makes the args (columns/data/rowActions) infer as UserRow.
const meta = {
  title: "App/Admin/DataTable",
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

// `rowActions` renders a trailing cell per row (DataTable has no per-column
// cell renderer); here it shows a role badge.
export const RowActionBadge: Story = {
  args: {
    columns,
    data: rows,
    rowActions: (u) => (
      <Badge variant={u.role === "admin" ? "default" : "secondary"}>{u.role}</Badge>
    ),
  },
}
