import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconCheck } from "@tabler/icons-react"

import { Badge } from "./badge"

const meta = {
  title: "Primitives/Badge",
  component: Badge,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: ["default", "secondary", "destructive", "outline", "ghost", "link"],
    },
  },
  args: { children: "Badge", variant: "default" },
} satisfies Meta<typeof Badge>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {}

const VARIANTS = ["default", "secondary", "destructive", "outline", "ghost", "link"] as const

export const AllVariants: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      {VARIANTS.map((variant) => (
        <Badge key={variant} variant={variant}>
          {variant}
        </Badge>
      ))}
    </div>
  ),
}

export const WithIcon: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Badge><IconCheck data-icon="inline-start" /> Verified</Badge>
      <Badge variant="secondary"><IconCheck data-icon="inline-start" /> Done</Badge>
    </div>
  ),
}
