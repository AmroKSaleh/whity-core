import type { Meta, StoryObj } from "@storybook/react-vite"
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
} satisfies Meta<typeof TagInput>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {
  render: () => {
    const [value, setValue] = React.useState<string[]>([])
    return <TagInput id="sb-tags-empty" options={OPTIONS} value={value} onChange={setValue} />
  },
}

export const SomeSelected: Story = {
  render: () => {
    const [value, setValue] = React.useState<string[]>(["typescript", "tailwind"])
    return <TagInput id="sb-tags-some" options={OPTIONS} value={value} onChange={setValue} />
  },
}

export const AllSelected: Story = {
  render: () => {
    const [value, setValue] = React.useState<string[]>(OPTIONS.map((o) => o.value))
    return <TagInput id="sb-tags-all" options={OPTIONS} value={value} onChange={setValue} />
  },
}

export const Disabled: Story = {
  render: () => (
    <TagInput id="sb-tags-disabled" options={OPTIONS} value={["react", "nextjs"]} onChange={() => {}} disabled />
  ),
}
