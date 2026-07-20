import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { PluginFeature } from "@/lib/plugin-features"

import { EmbedScreen } from "./embed-screen"

const feature: PluginFeature = {
  id: "bom-designer",
  plugin: "BillOfMaterials",
  label: "BOM Designer",
  icon: "layout-grid",
  group: "plugins",
  order: 2,
  screen: "embed",
  resource: null,
  action: null,
  embed: { path: "/api/v1/bom/designer" },
  requiredPermission: "bom:write",
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
}

const meta = {
  title: "App/Plugin/EmbedScreen",
  component: EmbedScreen,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
  args: { feature },
} satisfies Meta<typeof EmbedScreen>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
