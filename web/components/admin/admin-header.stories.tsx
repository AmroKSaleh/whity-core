import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import { Button } from "@amroksaleh/ui/button"

import { AdminHeader } from "./admin-header"

const meta = {
  title: "App/Admin/AdminHeader",
  component: AdminHeader,
  tags: ["autodocs"],
  args: {
    title: "Users",
    description: "Manage who can access this tenant.",
  },
} satisfies Meta<typeof AdminHeader>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithAction: Story = {
  args: {
    action: <Button>Invite user</Button>,
  },
}

export const WithBreadcrumb: Story = {
  args: {
    breadcrumb: "Admin / Access / Users",
    action: <Button variant="outline">Export</Button>,
  },
}
