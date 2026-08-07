import type { Meta, StoryObj } from "@storybook/react-vite"

import { Popover, PopoverTrigger, PopoverContent } from "./popover"
import { Button } from "./button"

const meta = {
  title: "Primitives/Popover",
  component: Popover,
  tags: ["autodocs"],
} satisfies Meta<typeof Popover>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm">
          Open popover
        </Button>
      </PopoverTrigger>
      <PopoverContent>
        <div className="space-y-1">
          <h4 className="text-sm font-medium text-foreground">Dimensions</h4>
          <p className="text-xs text-muted-foreground">Set the dimensions for the layer.</p>
        </div>
      </PopoverContent>
    </Popover>
  ),
}

export const Sides: Story = {
  render: () => (
    <div className="flex gap-4">
      {(["top", "right", "bottom", "left"] as const).map((side) => (
        <Popover key={side}>
          <PopoverTrigger asChild>
            <Button variant="outline" size="sm">
              {side}
            </Button>
          </PopoverTrigger>
          <PopoverContent side={side}>Popover on the {side}</PopoverContent>
        </Popover>
      ))}
    </div>
  ),
}
