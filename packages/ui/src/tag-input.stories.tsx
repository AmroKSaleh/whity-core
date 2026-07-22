import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { TagInput, type TagOption } from "./tag-input"

const OPTIONS: TagOption[] = [
  { value: "clo-1", label: "CLO 1 — Recall" },
  { value: "clo-2", label: "CLO 2 — Apply" },
  { value: "clo-3", label: "CLO 3 — Analyze" },
  { value: "clo-4", label: "CLO 4 — Evaluate" },
  { value: "clo-5", label: "CLO 5 — Create" },
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
    const [value, setValue] = React.useState<string[]>(["clo-2", "clo-4"])
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
    <TagInput id="sb-tags-disabled" options={OPTIONS} value={["clo-1", "clo-3"]} onChange={() => {}} disabled />
  ),
}
