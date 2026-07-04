import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { BrandingSettings } from "./branding-settings"
import { capabilitiesHandler, defaultHandlers } from "../.storybook/mocks"

const meta = {
  title: "App/BrandingSettings",
  component: BrandingSettings,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof BrandingSettings>

export default meta
type Story = StoryObj<typeof meta>

const withWrite = [capabilitiesHandler(["settings:write", "settings:manage"]), ...defaultHandlers]

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
