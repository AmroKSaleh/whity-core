import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { ColorCard, WHITY_BRAND_PRESETS } from "./color-card"

const meta = {
  title: "Primitives/ColorCard",
  component: ColorCard,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof ColorCard>

export default meta
type Story = StoryObj<typeof meta>

export const MinimalColorCard: Story = {
  args: {
    colorName: "Whity Blue",
    mainHex: "#2563eb",
    mainShade: 600,
  },
}

export const NeutralGreysColorGuide: Story = {
  args: {
    colorName: "Whity Greys",
    mainHex: "#64748b",
    mainShade: 500,
  },
}

export const MinimalPastelColorCard: Story = {
  args: {
    colorName: "Whity Pastel Blue",
    mainHex: "#93c5fd",
    mainShade: 300,
  },
}

export const BrandingGuidePaletteGallery: Story = {
  render: () => (
    <div className="space-y-4 max-w-4xl">
      <div className="space-y-1">
        <h2 className="text-xl font-bold text-foreground font-heading">Whity Brand System Color Palette</h2>
        <p className="text-xs text-muted-foreground">
          Ultra-minimal color cards with 10 even shade tokens (50 to 900, zero black/white), MAIN brand color token border highlights, neutral greys guide, and one-click copy hex functionality.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4">
        {WHITY_BRAND_PRESETS.map((preset) => (
          <ColorCard
            key={preset.name}
            colorName={preset.name}
            mainHex={preset.hex}
            mainShade={preset.mainShade}
          />
        ))}
      </div>
    </div>
  ),
}
