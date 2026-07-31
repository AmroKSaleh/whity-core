import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"
import { IconBuilding, IconHome, IconSettings, IconUsers, IconUsersGroup } from "@tabler/icons-react"

import { AppSidebar, type AppSidebarNavGroup } from "./app-sidebar"
import { Switcher, type SwitcherItem } from "./switcher"

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
      <div className="relative h-96 overflow-hidden rounded-lg bg-background">
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

const TENANTS: SwitcherItem[] = [
  { id: "1", label: "Acme Inc." },
  { id: "2", label: "Globex Corp." },
]
const TEAMS: SwitcherItem[] = [
  { id: "eng", label: "Engineering" },
  { id: "sales", label: "Sales" },
]

/**
 * Multi-tenant AND multi-team user: both switchers render, independently —
 * neither knows the other exists (they're just two Switcher instances
 * passed into the two dedicated slots).
 */
export const WithTenantAndTeamSwitchers: Story = {
  render: (args) => {
    const [tenantId, setTenantId] = React.useState(TENANTS[0].id)
    const [teamId, setTeamId] = React.useState(TEAMS[0].id)
    return (
      <AppSidebar
        {...args}
        tenantSwitcher={<Switcher items={TENANTS} activeId={tenantId} onChange={setTenantId} icon={<IconBuilding />} switchLabel="Tenant" />}
        teamSwitcher={<Switcher items={TEAMS} activeId={teamId} onChange={setTeamId} icon={<IconUsersGroup />} switchLabel="Team" />}
      />
    )
  },
}

/** Many teams, but only one tenant — the tenant slot degrades to a static label; only the team switcher is interactive. */
export const ManyTeamsSingleTenant: Story = {
  render: (args) => (
    <AppSidebar
      {...args}
      tenantSwitcher={<Switcher items={[TENANTS[0]]} activeId={TENANTS[0].id} onChange={() => {}} icon={<IconBuilding />} switchLabel="Tenant" />}
      teamSwitcher={<Switcher items={TEAMS} activeId={TEAMS[0].id} onChange={() => {}} icon={<IconUsersGroup />} switchLabel="Team" />}
    />
  ),
}

/** Many tenants, no teams feature for this tenant — team slot simply isn't rendered at all. */
export const ManyTenantsNoTeams: Story = {
  render: (args) => (
    <AppSidebar
      {...args}
      tenantSwitcher={<Switcher items={TENANTS} activeId={TENANTS[0].id} onChange={() => {}} icon={<IconBuilding />} switchLabel="Tenant" />}
    />
  ),
}
