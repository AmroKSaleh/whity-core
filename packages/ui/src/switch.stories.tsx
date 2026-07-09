import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { Switch } from "./switch"

const meta = {
  title: "Primitives/Switch",
  component: Switch,
  tags: ["autodocs"],
  args: { disabled: false },
} satisfies Meta<typeof Switch>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {}

export const Checked: Story = {
  args: { defaultChecked: true },
}

export const Disabled: Story = {
  render: () => (
    <div className="flex items-center gap-4">
      <Switch disabled />
      <Switch disabled defaultChecked />
    </div>
  ),
}

export const WithLabel: Story = {
  render: () => {
    const id = "sb-switch-self-registration"
    const [checked, setChecked] = React.useState(false)
    return (
      <label htmlFor={id} className="flex items-center gap-3 text-sm">
        <Switch id={id} checked={checked} onCheckedChange={setChecked} />
        <span>Allow public sign-up</span>
      </label>
    )
  },
}
