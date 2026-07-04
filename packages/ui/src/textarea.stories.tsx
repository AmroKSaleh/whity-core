import type { Meta, StoryObj } from "@storybook/react-vite"

import { Textarea } from "./textarea"

const meta = {
  title: "Primitives/Textarea",
  component: Textarea,
  tags: ["autodocs"],
  args: { placeholder: "Write a note…" },
} satisfies Meta<typeof Textarea>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {
  render: (args) => <Textarea {...args} className="w-80" />,
}

export const States: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-3">
      <Textarea placeholder="Default" />
      <Textarea placeholder="Disabled" disabled />
      <Textarea aria-invalid defaultValue="Something is wrong here" />
    </div>
  ),
}
