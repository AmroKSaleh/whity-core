import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { TopNav } from "./top-nav"
import { Breadcrumb } from "./breadcrumb"

const meta = {
  title: "Layout/TopNav",
  component: TopNav,
  tags: ["autodocs"],
} satisfies Meta<typeof TopNav>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    leftContent: <Breadcrumb items={[{ label: "Dashboard", href: "#" }, { label: "Overview" }]} />,
    user: {
      name: "Amro K. Saleh",
      email: "amro@example.com",
      initials: "AS",
    },
    notificationCount: 3,
    language: "en",
    theme: "light",
    onSearchClick: () => alert("Opened Command Palette (⌘K)"),
    onNotificationClick: () => alert("Opened Notifications Drawer"),
    onLanguageToggle: () => alert("Toggled Language"),
    onThemeToggle: () => alert("Toggled Theme Mode"),
    onProfileClick: () => alert("Opened Profile"),
    onSettingsClick: () => alert("Opened Settings"),
    onLogoutClick: () => alert("Signed Out"),
  },
}

export const SimpleWithTitle: Story = {
  args: {
    leftContent: <h2 className="text-sm font-bold tracking-tight text-foreground">Analytics Overview</h2>,
    user: {
      name: "Jane Doe",
      email: "jane@example.com",
      initials: "JD",
    },
    notificationCount: 0,
  },
}

export const MobileView: Story = {
  args: {
    leftContent: <span className="text-xs font-semibold">Whity Dashboard</span>,
    onMobileMenuToggle: () => alert("Toggled Mobile Sidebar"),
    user: {
      name: "Amro K. Saleh",
      email: "amro@example.com",
    },
    notificationCount: 5,
  },
}
