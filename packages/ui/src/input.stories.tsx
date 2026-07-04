import type { Meta, StoryObj } from "@storybook/react-vite"

import { Input } from "./input"

const meta = {
  title: "Primitives/Input",
  component: Input,
  tags: ["autodocs"],
  args: { placeholder: "you@example.com" },
} satisfies Meta<typeof Input>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {}

export const Types: Story = {
  render: () => (
    <div className="flex w-72 flex-col gap-3">
      <Input placeholder="Text" />
      <Input type="email" placeholder="Email" />
      <Input type="password" placeholder="Password" />
      <Input type="number" placeholder="42" />
      <Input type="file" />
    </div>
  ),
}

export const States: Story = {
  render: () => (
    <div className="flex w-72 flex-col gap-3">
      <Input placeholder="Default" />
      <Input placeholder="Disabled" disabled />
      <Input placeholder="Invalid" aria-invalid defaultValue="not-an-email" />
    </div>
  ),
}
