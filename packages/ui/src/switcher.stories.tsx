import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"
import { IconBuilding, IconUsersGroup } from "@tabler/icons-react"

import { Switcher, type SwitcherItem } from "./switcher"

const TENANTS: SwitcherItem[] = [
  { id: "1", label: "Acme Inc." },
  { id: "2", label: "Globex Corp." },
  { id: "3", label: "Initech" },
]

const TEAMS: SwitcherItem[] = [
  { id: "eng", label: "Engineering" },
  { id: "sales", label: "Sales" },
]

const meta = {
  title: "Layout/Switcher",
  component: Switcher,
  tags: ["autodocs"],
} satisfies Meta<typeof Switcher>

export default meta
type Story = StoryObj<typeof meta>

function Controlled({ items, ...props }: Omit<React.ComponentProps<typeof Switcher>, "activeId" | "onChange"> & { items: SwitcherItem[] }) {
  const [activeId, setActiveId] = React.useState(items[0]?.id)
  return <Switcher {...props} items={items} activeId={activeId} onChange={setActiveId} />
}

export const TenantSwitcherManyTenants: Story = {
  name: "Tenant switcher — many tenants",
  render: () => (
    <div className="w-64">
      <Controlled items={TENANTS} icon={<IconBuilding />} switchLabel="Tenant" />
    </div>
  ),
}

export const TeamSwitcherManyTeams: Story = {
  name: "Team switcher — many teams",
  render: () => (
    <div className="w-64">
      <Controlled items={TEAMS} icon={<IconUsersGroup />} switchLabel="Team" />
    </div>
  ),
}

export const SingleItem: Story = {
  name: "Single item — static label, no dropdown",
  render: () => (
    <div className="w-64">
      <Controlled items={[TENANTS[0]]} icon={<IconBuilding />} switchLabel="Tenant" />
    </div>
  ),
}

export const NoItems: Story = {
  name: "No items — static empty label",
  render: () => (
    <div className="w-64">
      <Controlled items={[]} icon={<IconUsersGroup />} switchLabel="Team" />
    </div>
  ),
}

export const Collapsed: Story = {
  render: () => (
    <div className="w-16">
      <Controlled items={TENANTS} icon={<IconBuilding />} switchLabel="Tenant" collapsed />
    </div>
  ),
}

export const BothStacked: Story = {
  name: "Tenant + team stacked (as used in AppSidebar)",
  render: () => (
    <div className="w-64 space-y-1.5">
      <Controlled items={TENANTS} icon={<IconBuilding />} switchLabel="Tenant" />
      <Controlled items={TEAMS} icon={<IconUsersGroup />} switchLabel="Team" />
    </div>
  ),
}
