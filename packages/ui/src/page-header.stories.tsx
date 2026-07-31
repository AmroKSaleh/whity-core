import type { Meta, StoryObj } from "@storybook/react-vite"

import { PageHeader } from "./page-header"
import { Button } from "./button"
import { Breadcrumb } from "./breadcrumb"

const meta = {
  title: "Layout/PageHeader",
  component: PageHeader,
  tags: ["autodocs"],
  args: {
    title: "Team members",
    description: "Manage who has access to this workspace.",
  },
} satisfies Meta<typeof PageHeader>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithAction: Story = {
  args: {
    action: <Button size="sm">Invite member</Button>,
  },
}

export const WithBreadcrumb: Story = {
  args: {
    breadcrumb: <Breadcrumb items={[{ label: "Settings", href: "#" }, { label: "Team" }]} />,
    action: <Button size="sm">Invite member</Button>,
  },
}
