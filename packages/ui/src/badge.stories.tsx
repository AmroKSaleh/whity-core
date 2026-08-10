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
        "info-solid",
        "success",
        "success-solid",
        "warning",
        "warning-solid",
        "destructive",
        "destructive-solid",
        "purple",
        "purple-solid",
        "outline",
        "ghost",
        "link",
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
  "info-solid",
  "success",
  "success-solid",
  "warning",
  "warning-solid",
  "destructive",
  "destructive-solid",
  "purple",
  "purple-solid",
  "outline",
  "ghost",
  "link",
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
        <Badge variant="info-solid">
          <IconInfoCircle data-icon="inline-start" />
          Info Solid
        </Badge>
        <Badge variant="success">
          <IconCheck data-icon="inline-start" />
          Success Subtle
        </Badge>
        <Badge variant="success-solid">
          <IconCheck data-icon="inline-start" />
          Success Solid
        </Badge>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Badge variant="warning">
          <IconAlertTriangle data-icon="inline-start" />
          Warning Subtle
        </Badge>
        <Badge variant="warning-solid">
          <IconAlertTriangle data-icon="inline-start" />
          Warning Solid
        </Badge>
        <Badge variant="destructive">
          <IconAlertTriangle data-icon="inline-start" />
          Destructive Subtle
        </Badge>
        <Badge variant="destructive-solid">
          <IconAlertTriangle data-icon="inline-start" />
          Destructive Solid
        </Badge>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Badge variant="purple">
          <IconSparkles data-icon="inline-start" />
          Purple Subtle
        </Badge>
        <Badge variant="purple-solid">
          <IconSparkles data-icon="inline-start" />
          Purple Solid
        </Badge>
      </div>
    </div>
  ),
}
