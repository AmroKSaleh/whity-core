import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import { http, HttpResponse } from "msw"

import { BrandingSettings } from "./branding-settings"
import { capabilitiesHandler, defaultHandlers } from "../.storybook/mocks"

const meta = {
  title: "App/BrandingSettings",
  component: BrandingSettings,
  tags: ["autodocs"],
  // The main colour refreshes the app router after a save.
  parameters: { layout: "padded", nextjs: { appDirectory: true } },
} satisfies Meta<typeof BrandingSettings>

export default meta
type Story = StoryObj<typeof meta>

// Saving the main colour PATCHes the tenant or global settings.
const saveSettings = http.patch("*/api/v1/settings*", () => HttpResponse.json({ data: {} }))

const withWrite = [capabilitiesHandler(["settings:write", "settings:manage"]), saveSettings, ...defaultHandlers]

// Tenant admin who can override per-tenant branding assets.
export const TenantOverridable: Story = {
  args: { tenantOverridable: true },
  parameters: { msw: { handlers: withWrite } },
}

// Read-only view: caller lacks settings:write, so uploads are disabled.
export const ReadOnly: Story = {
  args: { tenantOverridable: true },
  parameters: { msw: { handlers: [capabilitiesHandler([]), ...defaultHandlers] } },
}

// System tenant: only the global (platform-wide) assets are editable.
export const SystemTenant: Story = {
  args: { tenantOverridable: false },
  parameters: { msw: { handlers: withWrite } },
}
