import type { Meta, StoryObj } from "@storybook/react-vite"

import { RadioGroup, RadioGroupItem } from "./radio-group"

const meta = {
  title: "Primitives/RadioGroup",
  component: RadioGroup,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof RadioGroup>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <RadioGroup defaultValue="mine" {...args}>
      <label className="flex items-center gap-2">
        <RadioGroupItem value="mine" /> Keep mine
      </label>
      <label className="flex items-center gap-2">
        <RadioGroupItem value="theirs" /> Keep theirs
      </label>
    </RadioGroup>
  ),
}
