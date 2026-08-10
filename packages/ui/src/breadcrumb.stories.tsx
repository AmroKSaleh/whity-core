import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconBuilding, IconFolder, IconUsers } from "@tabler/icons-react"

import { Breadcrumb } from "./breadcrumb"

const meta = {
  title: "Primitives/Breadcrumb",
  component: Breadcrumb,
  tags: ["autodocs"],
} satisfies Meta<typeof Breadcrumb>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    items: [
      { label: "Admin", href: "/admin" },
      { label: "Users", href: "/admin/users" },
      { label: "Jane Doe" },
    ],
  },
}

export const WithHomeIcon: Story = {
  args: {
    showHomeIcon: true,
    items: [
      { label: "Home", href: "/" },
      { label: "Settings", href: "/settings" },
      { label: "Team Members" },
    ],
  },
}

export const WithCustomIcons: Story = {
  args: {
    items: [
      { label: "Organization", href: "#", icon: <IconBuilding /> },
      { label: "Projects", href: "#", icon: <IconFolder /> },
      { label: "Engineering Team", icon: <IconUsers /> },
    ],
  },
}

export const SingleLevel: Story = {
  args: {
    showHomeIcon: true,
    items: [{ label: "Dashboard" }],
  },
}
