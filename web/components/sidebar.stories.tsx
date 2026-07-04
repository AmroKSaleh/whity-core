import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { Sidebar } from "./sidebar"
import { capabilitiesHandler, defaultHandlers, http, HttpResponse, MOCK_USER } from "../.storybook/mocks"

const meta = {
  title: "App/Sidebar",
  component: Sidebar,
  tags: ["autodocs"],
  parameters: {
    layout: "fullscreen",
    nextjs: { navigation: { pathname: "/admin/users" } },
  },
} satisfies Meta<typeof Sidebar>

export default meta
type Story = StoryObj<typeof meta>

// Two memberships → the tenant switcher renders as a dropdown.
export const MultiTenant: Story = {}

// A single membership → the switcher collapses to a plain tenant label.
export const SingleTenant: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/me", () =>
          HttpResponse.json({
            user: MOCK_USER,
            memberships: [{ tenant_id: 10, tenant_name: "Acme Corp", role: "admin" }],
          })
        ),
        ...defaultHandlers,
      ],
    },
  },
}

// Empty nav response → the sidebar shows its no-navigation state.
export const NoNavigation: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/navigation", () => HttpResponse.json({ data: [] })),
        capabilitiesHandler([]),
        ...defaultHandlers,
      ],
    },
  },
}
