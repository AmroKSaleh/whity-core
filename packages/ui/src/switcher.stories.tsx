import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
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
  args: {
    items: TENANTS,
    icon: <IconBuilding />,
    switchLabel: "Tenant",
    onChange: fn(),
  },
} satisfies Meta<typeof Switcher>

export default meta
type Story = StoryObj<typeof meta>

/**
 * `Switcher` is controlled, so the stories drive it through this wrapper: the
 * active item is held locally (starting at the first of the `items` arg) and
 * the `onChange` arg still fires, so the Actions panel logs every switch.
 */
function Controlled({ items, onChange, ...props }: React.ComponentProps<typeof Switcher>) {
  const [activeId, setActiveId] = React.useState(items[0]?.id)

  return (
    <Switcher
      {...props}
      items={items}
      activeId={activeId}
      onChange={(id) => {
        setActiveId(id)
        onChange(id)
      }}
    />
  )
}

export const TenantSwitcherManyTenants: Story = {
  name: "Tenant switcher — many tenants",
  render: (args) => (
    <div className="w-64">
      <Controlled {...args} />
    </div>
  ),
}

export const TeamSwitcherManyTeams: Story = {
  name: "Team switcher — many teams",
  args: { items: TEAMS, icon: <IconUsersGroup />, switchLabel: "Team" },
  render: (args) => (
    <div className="w-64">
      <Controlled {...args} />
    </div>
  ),
}

export const SingleItem: Story = {
  name: "Single item — static label, no dropdown",
  args: { items: [TENANTS[0]] },
  render: (args) => (
    <div className="w-64">
      <Controlled {...args} />
    </div>
  ),
}

export const NoItems: Story = {
  name: "No items — static empty label",
  args: { items: [], icon: <IconUsersGroup />, switchLabel: "Team" },
  render: (args) => (
    <div className="w-64">
      <Controlled {...args} />
    </div>
  ),
}

export const Collapsed: Story = {
  args: { collapsed: true },
  render: (args) => (
    <div className="w-16">
      <Controlled {...args} />
    </div>
  ),
}

export const BothStacked: Story = {
  name: "Tenant + team stacked (as used in AppSidebar)",
  render: (args) => (
    <div className="w-64 space-y-1.5">
      <Controlled {...args} />
      <Controlled {...args} items={TEAMS} icon={<IconUsersGroup />} switchLabel="Team" />
    </div>
  ),
}
