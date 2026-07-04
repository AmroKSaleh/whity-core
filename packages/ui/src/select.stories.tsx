import type { Meta, StoryObj } from "@storybook/react-vite"

import {
  Select,
  SelectGroup,
  SelectValue,
  SelectTrigger,
  SelectContent,
  SelectLabel,
  SelectItem,
  SelectSeparator,
} from "./select"

const meta = {
  title: "Primitives/Select",
  component: Select,
  tags: ["autodocs"],
} satisfies Meta<typeof Select>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <Select>
      <SelectTrigger className="w-56">
        <SelectValue placeholder="Select a region" />
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          <SelectLabel>Europe</SelectLabel>
          <SelectItem value="eu-central">eu-central</SelectItem>
          <SelectItem value="eu-west">eu-west</SelectItem>
        </SelectGroup>
        <SelectSeparator />
        <SelectGroup>
          <SelectLabel>Americas</SelectLabel>
          <SelectItem value="us-east">us-east</SelectItem>
          <SelectItem value="us-west" disabled>us-west (soon)</SelectItem>
        </SelectGroup>
      </SelectContent>
    </Select>
  ),
}
