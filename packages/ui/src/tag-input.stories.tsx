import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"

import { TagInput, type TagOption } from "./tag-input"

const OPTIONS: TagOption[] = [
  { value: "react", label: "React" },
  { value: "typescript", label: "TypeScript" },
  { value: "nextjs", label: "Next.js" },
  { value: "tailwind", label: "Tailwind CSS" },
  { value: "storybook", label: "Storybook" },
]

const meta = {
  title: "Primitives/TagInput",
  component: TagInput,
  tags: ["autodocs"],
  args: {
    options: OPTIONS,
    value: [],
    onChange: fn(),
  },
} satisfies Meta<typeof TagInput>

export default meta
type Story = StoryObj<typeof meta>

/**
 * `TagInput` is fully controlled, so the interactive stories drive it through
 * this wrapper: the `value` arg seeds local state and the `onChange` arg still
 * fires — so the Actions panel logs selections — while the field stays usable
 * in the canvas.
 */
function ControlledTagInput({ value, onChange, ...props }: React.ComponentProps<typeof TagInput>) {
  const [current, setCurrent] = React.useState<string[]>(value)

  return (
    <TagInput
      {...props}
      value={current}
      onChange={(next) => {
        setCurrent(next)
        onChange(next)
      }}
    />
  )
}

export const Empty: Story = {
  args: { id: "sb-tags-empty" },
  render: (args) => <ControlledTagInput {...args} />,
}

export const SomeSelected: Story = {
  args: { id: "sb-tags-some", value: ["typescript", "tailwind"] },
  render: (args) => <ControlledTagInput {...args} />,
}

export const AllSelected: Story = {
  args: { id: "sb-tags-all", value: OPTIONS.map((o) => o.value) },
  render: (args) => <ControlledTagInput {...args} />,
}

export const Disabled: Story = {
  args: { id: "sb-tags-disabled", value: ["react", "nextjs"], disabled: true },
}
