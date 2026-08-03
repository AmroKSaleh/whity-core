import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"
import { IconArrowLeft, IconBuilding, IconHome, IconRefresh, IconSettings, IconUsers } from "@tabler/icons-react"

import { Button } from "./button"
import { AccessDenied, type SearchResultItem } from "./access-denied"

const meta = {
  title: "Primitives/AccessDenied",
  component: AccessDenied,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
  argTypes: {
    variant: {
      control: "select",
      options: ["forbidden", "unauthorized", "not-found", "error", "success", "maintenance"],
    },
    showSearch: { control: "boolean" },
  },
} satisfies Meta<typeof AccessDenied>

export default meta
type Story = StoryObj<typeof meta>

const SAMPLE_RESULTS: SearchResultItem[] = [
  {
    id: "1",
    title: "Organization Unit Settings",
    description: "Manage OU hierarchy, permissions, and tenant scope.",
    href: "/settings/ou",
    category: "Settings",
    icon: <IconBuilding />,
  },
  {
    id: "2",
    title: "User Management & Governance",
    description: "Invite users, assign roles, and review approval requests.",
    href: "/admin/users",
    category: "Admin",
    icon: <IconUsers />,
  },
  {
    id: "3",
    title: "General Preferences",
    description: "Configure workspace theme, default language, and branding.",
    href: "/settings/general",
    category: "Preferences",
    icon: <IconSettings />,
  },
]

export const NotFoundWithSiteSearch: Story = {
  args: {
    variant: "not-found",
    title: "Page Not Found",
    description: "The page or resource you requested could not be found. Try searching our site or head back to the dashboard.",
    showSearch: true,
    searchPlaceholder: "Search documentation, resources, or settings...",
    onSearch: (query) => alert(`Searching for: ${query}`),
    primaryAction: {
      label: "Back to Home",
      icon: <IconHome />,
      href: "/",
    },
  },
}

export const NotFoundWithSearchResults: Story = {
  args: {
    variant: "not-found",
    title: "Page Not Found",
    description: "The page you requested could not be found. Here are relevant resources matching your site search:",
    showSearch: true,
    searchResults: SAMPLE_RESULTS,
    primaryAction: {
      label: "Back to Home",
      icon: <IconHome />,
      href: "/",
    },
  },
}

export const NotFoundInteractiveSearch: Story = {
  render: () => {
    const [query, setQuery] = React.useState("")
    const filtered = query.trim()
      ? SAMPLE_RESULTS.filter(
          (item) =>
            item.title.toLowerCase().includes(query.toLowerCase()) ||
            item.description?.toLowerCase().includes(query.toLowerCase())
        )
      : []

    return (
      <AccessDenied
        variant="not-found"
        title="Page Not Found"
        description="Try searching for settings, users, or general options:"
        showSearch
        searchPlaceholder="Type 'users' or 'settings'..."
        onSearch={setQuery}
        searchResults={filtered}
        primaryAction={{
          label: "Back to Home",
          icon: <IconHome />,
          href: "/",
        }}
      />
    )
  },
}

export const ForbiddenWithActions: Story = {
  args: {
    variant: "forbidden",
    description: (
      <>
        You do not have the required permission (<code>settings:read</code>) to view
        System Settings. Contact your tenant administrator to request access.
      </>
    ),
    primaryAction: {
      label: "Go to Dashboard",
      icon: <IconHome />,
      href: "/",
    },
    secondaryAction: {
      label: "Go Back",
      icon: <IconArrowLeft />,
      onClick: () => window.history.back(),
    },
  },
}

export const Unauthorized: Story = {
  args: {
    variant: "unauthorized",
    title: "Session Expired",
    description: "Your session has timed out. Please sign in again to continue accessing this workspace.",
    primaryAction: {
      label: "Sign In Again",
      href: "/login",
    },
  },
}

export const SystemError: Story = {
  args: {
    variant: "error",
    title: "Internal Server Error",
    description: "Something went wrong on our end while processing your request. Please try refreshing the page.",
    primaryAction: {
      label: "Refresh Page",
      icon: <IconRefresh />,
      onClick: () => window.location.reload(),
    },
  },
}

export const SuccessState: Story = {
  args: {
    variant: "success",
    title: "Setup Completed",
    description: "Your workspace and tenant governance settings have been initialized successfully.",
    primaryAction: {
      label: "View Dashboard",
      icon: <IconHome />,
      href: "/",
    },
  },
}

export const MaintenanceState: Story = {
  args: {
    variant: "maintenance",
    title: "Scheduled Maintenance",
    description: "This service is currently undergoing scheduled platform upgrades. We will be back online shortly.",
    primaryAction: {
      label: "Check Status",
      onClick: () => {},
    },
  },
}
