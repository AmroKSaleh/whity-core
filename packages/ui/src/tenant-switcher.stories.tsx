import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"

import { TenantSwitcher, type TenantSwitcherWorkspace } from "./tenant-switcher"

const WORKSPACES: TenantSwitcherWorkspace[] = [
  { id: "1", name: "Example Bakery", role: "Owner" },
  { id: "2", name: "Example Clinic", role: "Accountant" },
  { id: "3", name: "Example Hotel", role: "Viewer" },
  { id: "4", name: "Example Trading", role: "Admin" },
  { id: "5", name: "Example Office", role: "Accountant" },
]

const meta = {
  title: "Layout/TenantSwitcher",
  component: TenantSwitcher,
  tags: ["autodocs"],
  args: { workspaces: WORKSPACES, currentId: "1", onChange: fn(), onCreate: fn() },
  decorators: [(Story) => <div style={{ width: 216 }}><Story /></div>],
} satisfies Meta<typeof TenantSwitcher>

export default meta
type Story = StoryObj<typeof meta>

function Controlled({ currentId, onChange, ...props }: React.ComponentProps<typeof TenantSwitcher>) {
  const [current, setCurrent] = React.useState(currentId)
  return <TenantSwitcher {...props} currentId={current} onChange={(id) => { setCurrent(id); onChange(id) }} />
}

/** Five workspaces: the search box shows, and "Create workspace" sits at the foot. */
export const Default: Story = { render: (args) => <Controlled {...args} /> }

/** Two workspaces: no search box. */
export const Few: Story = { args: { workspaces: WORKSPACES.slice(0, 2) }, render: (args) => <Controlled {...args} /> }

/** The 56px rail: the logo alone. */
export const Collapsed: Story = { args: { collapsed: true }, render: (args) => <Controlled {...args} /> }
