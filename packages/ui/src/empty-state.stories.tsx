import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconPlus, IconRefresh } from "@tabler/icons-react"

import { EmptyState, ErrorState } from "./empty-state"
import { Button } from "./button"

const meta = {
  title: "Primitives/EmptyState",
  component: EmptyState,
  tags: ["autodocs"],
  argTypes: {
    variant: { control: "select", options: ["empty", "error"] },
  },
} satisfies Meta<typeof EmptyState>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    title: "No tenants yet",
    description: "Create your first tenant to get started.",
  },
  render: (args) => <EmptyState {...args} className="max-w-sm" />,
}

export const WithAction: Story = {
  args: {
    title: "No roles found",
    description: "Roles you create will show up here.",
    action: (
      <Button size="sm" variant="outline">
        <IconPlus /> Create role
      </Button>
    ),
  },
  render: (args) => <EmptyState {...args} className="max-w-sm" />,
}

export const Error: Story = {
  args: {
    title: "Couldn't load audit logs",
    description: "The request failed. Check your connection and try again.",
    action: (
      <Button size="sm" variant="outline">
        <IconRefresh /> Retry
      </Button>
    ),
  },
  render: (args) => <ErrorState {...args} className="max-w-sm" />,
}
