import * as React from "react"
import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconDots } from "@tabler/icons-react"

import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuCheckboxItem,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubTrigger,
  DropdownMenuSubContent,
} from "./dropdown-menu"
import { Button } from "./button"

const meta = {
  title: "Overlays/DropdownMenu",
  component: DropdownMenu,
  tags: ["autodocs"],
} satisfies Meta<typeof DropdownMenu>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => {
    const [notifications, setNotifications] = React.useState(true)
    const [region, setRegion] = React.useState("eu-central")
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="icon" aria-label="Open menu">
            <IconDots />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-52">
          <DropdownMenuLabel>Tenant actions</DropdownMenuLabel>
          <DropdownMenuGroup>
            <DropdownMenuItem>
              Manage <DropdownMenuShortcut>⌘M</DropdownMenuShortcut>
            </DropdownMenuItem>
            <DropdownMenuItem>View logs</DropdownMenuItem>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>Move to…</DropdownMenuSubTrigger>
              <DropdownMenuSubContent>
                <DropdownMenuItem>Staging</DropdownMenuItem>
                <DropdownMenuItem>Production</DropdownMenuItem>
              </DropdownMenuSubContent>
            </DropdownMenuSub>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuCheckboxItem
            checked={notifications}
            onCheckedChange={setNotifications}
          >
            Email notifications
          </DropdownMenuCheckboxItem>
          <DropdownMenuSeparator />
          <DropdownMenuLabel>Region</DropdownMenuLabel>
          <DropdownMenuRadioGroup value={region} onValueChange={setRegion}>
            <DropdownMenuRadioItem value="eu-central">eu-central</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="us-east">us-east</DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant="destructive">Delete tenant</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    )
  },
}
