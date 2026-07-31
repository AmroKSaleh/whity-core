import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconHome, IconSettings, IconUsers } from "@tabler/icons-react"

import { AppSidebar, type AppSidebarNavGroup } from "./app-sidebar"

const groups: AppSidebarNavGroup[] = [
  {
    id: "main",
    label: "Main",
    items: [
      { id: "home", label: "Home", href: "#", icon: <IconHome />, active: true },
      { id: "team", label: "Team", href: "#", icon: <IconUsers /> },
    ],
  },
  {
    id: "admin",
    label: "Admin",
    items: [{ id: "settings", label: "Settings", href: "#", icon: <IconSettings /> }],
  },
]

const meta = {
  title: "Layout/AppSidebar",
  component: AppSidebar,
  tags: ["autodocs"],
  args: { groups },
  decorators: [
    (Story) => (
      <div className="relative h-96 overflow-hidden rounded-lg border border-border">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof AppSidebar>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithHeaderAndFooter: Story = {
  args: {
    header: <div className="text-sm font-semibold text-sidebar-foreground">Whity</div>,
    footer: <div className="text-xs text-sidebar-foreground/70">signed-in-user@example.com</div>,
  },
}

export const Collapsed: Story = {
  args: { collapsed: true },
}
