import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { PermissionButton } from "./permission-button"
import { capabilitiesHandler, defaultHandlers } from "../../.storybook/mocks"

const meta = {
  title: "App/RBAC/PermissionButton",
  component: PermissionButton,
  tags: ["autodocs"],
  args: { permission: "users:write", children: "Edit user" },
} satisfies Meta<typeof PermissionButton>

export default meta
type Story = StoryObj<typeof meta>

// Caller holds `users:write` → renders a normal, clickable button.
export const Allowed: Story = {
  parameters: {
    msw: { handlers: [capabilitiesHandler(["users:write"]), ...defaultHandlers] },
  },
}

// Caller lacks the permission, non-destructive → disabled + tooltip reason.
export const Disabled: Story = {
  parameters: {
    msw: { handlers: [capabilitiesHandler([]), ...defaultHandlers] },
  },
}

// Caller lacks the permission, destructive → hidden entirely (renders nothing).
export const HiddenWhenDestructive: Story = {
  args: { permission: "users:delete", destructive: true, variant: "destructive", children: "Delete user" },
  parameters: {
    msw: { handlers: [capabilitiesHandler([]), ...defaultHandlers] },
  },
  render: (args) => (
    <div className="flex items-center gap-2">
      <span className="text-muted-foreground text-xs">(nothing renders →)</span>
      <PermissionButton {...args} />
    </div>
  ),
}
