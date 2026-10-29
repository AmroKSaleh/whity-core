import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconAlertTriangle, IconCheck, IconInfoCircle, IconSparkles } from "@tabler/icons-react"

import { Badge } from "./badge"

const meta = {
  title: "Primitives/Badge",
  component: Badge,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: [
        "default",
        "secondary",
        "info",
        "success",
        "warning",
        "destructive",
        "outline",
      ],
    },
  },
  args: { children: "Badge", variant: "default" },
} satisfies Meta<typeof Badge>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {}

const VARIANTS = [
  "default",
  "secondary",
  "info",
  "success",
  "warning",
  "destructive",
  "outline",
] as const

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

export const SubtleVsSolid: Story = {
  render: () => (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <Badge variant="info">
          <IconInfoCircle data-icon="inline-start" />
          Info Subtle
        </Badge>
        <Badge variant="info">
          <IconInfoCircle data-icon="inline-start" />
          Info Solid
        </Badge>
        <Badge variant="success">
          <IconCheck data-icon="inline-start" />
          Success Subtle
        </Badge>
        <Badge variant="success">
          <IconCheck data-icon="inline-start" />
          Success Solid
        </Badge>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Badge variant="warning">
          <IconAlertTriangle data-icon="inline-start" />
          Warning Subtle
        </Badge>
        <Badge variant="warning">
          <IconAlertTriangle data-icon="inline-start" />
          Warning Solid
        </Badge>
        <Badge variant="destructive">
          <IconAlertTriangle data-icon="inline-start" />
          Destructive Subtle
        </Badge>
        <Badge variant="destructive">
          <IconAlertTriangle data-icon="inline-start" />
          Destructive Solid
        </Badge>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Badge variant="secondary">
          <IconSparkles data-icon="inline-start" />
          Purple Subtle
        </Badge>
        <Badge variant="secondary">
          <IconSparkles data-icon="inline-start" />
          Purple Solid
        </Badge>
      </div>
    </div>
  ),
}
