import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { PluginFeature } from "@/lib/plugin-features"

import { ActionScreen } from "./action-screen"
import { defaultHandlers, http, HttpResponse } from "../../.storybook/mocks"

const feature: PluginFeature = {
  id: "bom-import",
  plugin: "BillOfMaterials",
  label: "Import BOM",
  icon: "file-import",
  group: "plugins",
  order: 2,
  screen: "action",
  resource: null,
  action: {
    method: "POST",
    path: "/api/v1/bom/documents",
    submitLabel: "Generate document",
    fields: [
      { name: "title", label: "Document title", kind: "text", accept: null, required: true },
      { name: "notes", label: "Notes", kind: "textarea", accept: null, required: false },
      { name: "source", label: "Source CSV", kind: "file", accept: ".csv", required: true },
    ],
  },
  requiredPermission: "bom:write",
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
}

const meta = {
  title: "App/Plugin/ActionScreen",
  component: ActionScreen,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    msw: {
      handlers: [
        http.post("*/api/v1/bom/documents", () => HttpResponse.json({ ok: true })),
        ...defaultHandlers,
      ],
    },
  },
  args: { feature },
} satisfies Meta<typeof ActionScreen>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const NoFields: Story = {
  args: {
    feature: {
      ...feature,
      label: "Run nightly sync",
      action: { method: "POST", path: "/api/v1/bom/sync", submitLabel: null, fields: [] },
    },
  },
}
