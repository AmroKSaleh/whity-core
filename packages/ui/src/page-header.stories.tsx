import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconDownload, IconPlus, IconUsers } from "@tabler/icons-react"

import { PageHeader } from "./page-header"
import { Button } from "./button"
import { Breadcrumb } from "./breadcrumb"
import { Badge } from "./badge"
import { Tabs, TabsList, TabsTrigger } from "./tabs"

const meta = {
  title: "Layout/PageHeader",
  component: PageHeader,
  tags: ["autodocs"],
  args: {
    title: "Team Members",
    description: "Manage who has access to this workspace and configure permission roles.",
  },
} satisfies Meta<typeof PageHeader>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithAction: Story = {
  args: {
    action: (
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm">
          <IconDownload className="size-3.5" />
          Export CSV
        </Button>
        <Button size="sm">
          <IconPlus className="size-3.5" />
          Invite Member
        </Button>
      </div>
    ),
  },
}

export const RichHeaderWithIconAndBadge: Story = {
  args: {
    icon: <IconUsers />,
    badge: <Badge variant="secondary">24 Active</Badge>,
    breadcrumb: <Breadcrumb items={[{ label: "Organization", href: "#" }, { label: "Team Members" }]} />,
    action: (
      <Button size="sm">
        <IconPlus className="size-3.5" />
        Invite Member
      </Button>
    ),
  },
}

export const CardVariant: Story = {
  args: {
    variant: "card",
    icon: <IconUsers />,
    badge: <Badge variant="outline">Enterprise</Badge>,
    breadcrumb: <Breadcrumb items={[{ label: "Dashboard", href: "#" }, { label: "Settings" }, { label: "Team" }]} />,
    action: (
      <Button size="sm">
        <IconPlus className="size-3.5" />
        Add Member
      </Button>
    ),
  },
}

export const WithEmbeddedTabs: Story = {
  render: () => (
    <PageHeader
      title="User Management"
      description="View, edit, and audit system users and role permissions."
      icon={<IconUsers />}
      badge={<Badge variant="secondary">v2.4.0</Badge>}
      breadcrumb={<Breadcrumb items={[{ label: "Admin", href: "#" }, { label: "Users" }]} />}
      action={
        <Button size="sm">
          <IconPlus className="size-3.5" />
          New User
        </Button>
      }
    >
      <Tabs defaultValue="all" className="w-full">
        <TabsList>
          <TabsTrigger value="all">All Users</TabsTrigger>
          <TabsTrigger value="admins">Administrators</TabsTrigger>
          <TabsTrigger value="pending">Pending Invites</TabsTrigger>
        </TabsList>
      </Tabs>
    </PageHeader>
  ),
}
