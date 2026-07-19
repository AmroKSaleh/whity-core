import type { Meta, StoryObj } from "@storybook/react-vite"

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

export const SingleLevel: Story = {
  args: {
    items: [{ label: "Dashboard" }],
  },
}
