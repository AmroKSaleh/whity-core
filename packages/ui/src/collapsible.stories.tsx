import type { Meta, StoryObj } from "@storybook/react-vite"

import { Collapsible, CollapsibleTrigger, CollapsibleContent } from "./collapsible"
import { Button } from "./button"

const meta = {
  title: "Primitives/Collapsible",
  component: Collapsible,
  tags: ["autodocs"],
} satisfies Meta<typeof Collapsible>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <Collapsible className="w-72 space-y-2">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-foreground">3 items starred</span>
        <CollapsibleTrigger asChild>
          <Button variant="ghost" size="sm">
            Toggle
          </Button>
        </CollapsibleTrigger>
      </div>
      <div className="rounded-md border border-border px-3 py-2 text-xs text-muted-foreground">whity-core</div>
      <CollapsibleContent className="space-y-2">
        <div className="rounded-md border border-border px-3 py-2 text-xs text-muted-foreground">whity-plugins</div>
        <div className="rounded-md border border-border px-3 py-2 text-xs text-muted-foreground">whity-desktop</div>
      </CollapsibleContent>
    </Collapsible>
  ),
}

export const DefaultOpen: Story = {
  render: () => (
    <Collapsible defaultOpen className="w-72 space-y-2">
      <CollapsibleTrigger asChild>
        <Button variant="outline" size="sm">
          Toggle (starts open)
        </Button>
      </CollapsibleTrigger>
      <CollapsibleContent className="rounded-md border border-border px-3 py-2 text-xs text-muted-foreground">
        Visible by default — click the trigger to collapse.
      </CollapsibleContent>
    </Collapsible>
  ),
}
