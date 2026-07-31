import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"
import { IconBuilding, IconDotsVertical, IconHome, IconSettings, IconUsers, IconUsersGroup } from "@tabler/icons-react"

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

/** Headerless sidebar with Switchers — verifies switchers render cleanly at the top without collapsing or colliding with the mobile close icon. */
export const HeaderlessWithSwitchers: Story = {
  render: (args) => {
    const [tenantId, setTenantId] = React.useState(TENANTS[0].id)
    return (
      <AppSidebar
        {...args}
        header={null}
        tenantSwitcher={<Switcher items={TENANTS} activeId={tenantId} onChange={setTenantId} icon={<IconBuilding />} switchLabel="Tenant" />}
      />
    )
  },
}

/**
 * Full-featured sidebar: Header + Both Switchers (Tenant & Team) + Nav Groups + Footer Profile Icon & User Info.
 */
export const FullFeaturedWithProfileIcon: Story = {
  render: (args) => {
    const [tenantId, setTenantId] = React.useState(TENANTS[0].id)
    const [teamId, setTeamId] = React.useState(TEAMS[0].id)
    const [collapsed, setCollapsed] = React.useState(false)

    return (
      <AppSidebar
        {...args}
        collapsed={collapsed}
        onCollapsedChange={setCollapsed}
        header={
          <div className="flex items-center gap-2.5">
            <div className="flex size-7 items-center justify-center rounded-lg bg-primary text-primary-foreground font-bold text-xs shadow-2xs">
              W
            </div>
            {!collapsed && (
              <div className="flex flex-col">
                <span className="text-sm font-bold tracking-tight text-sidebar-foreground">Whity Engine</span>
                <span className="text-[0.625rem] text-sidebar-foreground/70 font-mono">v2.4.0</span>
              </div>
            )}
          </div>
        }
        tenantSwitcher={
          <Switcher
            items={TENANTS}
            activeId={tenantId}
            onChange={setTenantId}
            collapsed={collapsed}
            icon={<IconBuilding />}
            switchLabel="Tenant"
          />
        }
        teamSwitcher={
          <Switcher
            items={TEAMS}
            activeId={teamId}
            onChange={setTeamId}
            collapsed={collapsed}
            icon={<IconUsersGroup />}
            switchLabel="Team"
          />
        }
        footer={
          collapsed ? (
            <div className="flex justify-center" title="Amro K. Saleh (amro@example.com)">
              <div className="flex size-8 items-center justify-center rounded-full bg-primary/20 text-primary font-bold text-xs ring-2 ring-primary/30">
                AS
              </div>
            </div>
          ) : (
            <div className="flex items-center justify-between gap-2.5 w-full">
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/20 text-primary font-bold text-xs ring-2 ring-primary/30">
                  AS
                </div>
                <div className="flex flex-col min-w-0 text-start">
                  <span className="truncate text-xs font-semibold text-sidebar-foreground">Amro K. Saleh</span>
                  <span className="truncate text-[0.625rem] text-sidebar-foreground/70 font-mono">amro@example.com</span>
                </div>
              </div>
              <button
                type="button"
                aria-label="User settings"
                className="flex size-6 shrink-0 items-center justify-center rounded text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
              >
                <IconDotsVertical className="size-4" />
              </button>
            </div>
          )
        }
      />
    )
  },
}
