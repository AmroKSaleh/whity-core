import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"
import { IconArrowLeft, IconHome, IconRefresh } from "@tabler/icons-react"

import { Button } from "./button"
import { AccessDenied } from "./access-denied"

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
  },
} satisfies Meta<typeof AccessDenied>

export default meta
type Story = StoryObj<typeof meta>

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

export const NotFound: Story = {
  args: {
    variant: "not-found",
    title: "Page Not Found",
    description: "The resource or page you requested could not be found. It may have been moved or deleted.",
    primaryAction: {
      label: "Back to Home",
      icon: <IconHome />,
      href: "/",
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

export const LegacyActionSlot: Story = {
  args: {
    description: "Access to this page is restricted.",
    action: (
      <Button variant="outline" size="lg" onClick={() => window.history.back()}>
        Go Back
      </Button>
    ),
  },
}
