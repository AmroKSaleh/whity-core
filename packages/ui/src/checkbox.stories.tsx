import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { Checkbox } from "./checkbox"

const meta = {
  title: "Primitives/Checkbox",
  component: Checkbox,
  tags: ["autodocs"],
} satisfies Meta<typeof Checkbox>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { "aria-label": "Accept terms" },
}

export const Checked: Story = {
  args: { "aria-label": "Accept terms", defaultChecked: true },
}

export const Indeterminate: Story = {
  args: { "aria-label": "Select all", checked: "indeterminate" },
}

export const Disabled: Story = {
  args: { "aria-label": "Accept terms", disabled: true },
}

export const SelectAllPattern: Story = {
  render: () => {
    const [items, setItems] = React.useState([false, true, false])
    const allChecked = items.every(Boolean)
    const someChecked = items.some(Boolean) && !allChecked

    return (
      <div className="flex flex-col gap-2">
        <label className="flex items-center gap-2">
          <Checkbox
            aria-label="Select all rows"
            checked={someChecked ? "indeterminate" : allChecked}
            onCheckedChange={(v) => setItems(items.map(() => v === true))}
          />
          Select all
        </label>
        {items.map((checked, i) => (
          <label key={i} className="flex items-center gap-2 ps-4">
            <Checkbox
              aria-label={`Row ${i + 1}`}
              checked={checked}
              onCheckedChange={(v) =>
                setItems(items.map((c, idx) => (idx === i ? v === true : c)))
              }
            />
            Row {i + 1}
          </label>
        ))}
      </div>
    )
  },
}
