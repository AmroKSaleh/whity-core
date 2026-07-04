import type { Meta, StoryObj } from "@storybook/react-vite"

import {
  Sheet,
  SheetTrigger,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
  SheetClose,
} from "./sheet"
import { Button } from "./button"

const meta = {
  title: "Overlays/Sheet",
  component: Sheet,
  tags: ["autodocs"],
} satisfies Meta<typeof Sheet>

export default meta
type Story = StoryObj<typeof meta>

const sides = ["right", "left", "top", "bottom"] as const

export const Sides: Story = {
  render: () => (
    <div className="flex flex-wrap gap-3">
      {sides.map((side) => (
        <Sheet key={side}>
          <SheetTrigger asChild>
            <Button variant="outline">{side}</Button>
          </SheetTrigger>
          <SheetContent side={side}>
            <SheetHeader>
              <SheetTitle>Tenant details</SheetTitle>
              <SheetDescription>Opens from the {side} edge.</SheetDescription>
            </SheetHeader>
            <div className="text-muted-foreground text-xs">
              Panel body content goes here.
            </div>
            <SheetClose asChild>
              <Button variant="outline" className="mt-auto">Close</Button>
            </SheetClose>
          </SheetContent>
        </Sheet>
      ))}
    </div>
  ),
}
