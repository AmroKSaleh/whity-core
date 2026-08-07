import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"
import { IconAlertTriangle, IconArrowRight, IconCheck, IconInfoCircle, IconPlus, IconSparkles } from "@tabler/icons-react"

import { Button } from "./button"

const meta = {
  title: "Primitives/Button",
  component: Button,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: [
        "default",
        "outline",
        "secondary",
        "ghost",
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
        "link",
      ],
    },
    size: {
      control: "select",
      options: ["default", "xs", "sm", "lg", "icon", "icon-xs", "icon-sm", "icon-lg"],
    },
    loading: { control: "boolean" },
    active: { control: "boolean" },
    disabled: { control: "boolean" },
  },
  args: { children: "Button", variant: "default", size: "default" },
} satisfies Meta<typeof Button>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {}

const VARIANTS = [
  "default",
  "outline",
  "secondary",
  "ghost",
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
  "link",
] as const

const SIZES = ["xs", "sm", "default", "lg"] as const

export const AllVariants: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      {VARIANTS.map((variant) => (
        <Button key={variant} variant={variant}>
          {variant}
        </Button>
      ))}
    </div>
  ),
}

export const SemanticVariants: Story = {
  render: () => (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="info"><IconInfoCircle /> Info</Button>
        <Button variant="info-solid"><IconInfoCircle /> Info Solid</Button>
        <Button variant="success"><IconCheck /> Success</Button>
        <Button variant="success-solid"><IconCheck /> Success Solid</Button>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="warning"><IconAlertTriangle /> Warning</Button>
        <Button variant="warning-solid"><IconAlertTriangle /> Warning Solid</Button>
        <Button variant="destructive"><IconAlertTriangle /> Destructive</Button>
        <Button variant="destructive-solid"><IconAlertTriangle /> Destructive Solid</Button>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="purple"><IconSparkles /> Purple Subtle</Button>
        <Button variant="purple-solid"><IconSparkles /> Purple Solid</Button>
      </div>
    </div>
  ),
}

export const Sizes: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      {SIZES.map((size) => (
        <Button key={size} size={size}>
          {size}
        </Button>
      ))}
    </div>
  ),
}

export const IconSizes: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Button size="icon-xs" aria-label="Add"><IconPlus /></Button>
      <Button size="icon-sm" aria-label="Add"><IconPlus /></Button>
      <Button size="icon" aria-label="Add"><IconPlus /></Button>
      <Button size="icon-lg" aria-label="Add"><IconPlus /></Button>
    </div>
  ),
}

export const WithIcons: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Button><IconPlus data-icon="inline-start" /> New Item</Button>
      <Button variant="outline">Continue <IconArrowRight data-icon="inline-end" /></Button>
    </div>
  ),
}

export const States: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Button loading>Loading</Button>
      <Button active>Active Toggle</Button>
      <Button disabled>Disabled</Button>
      <Button aria-invalid>Invalid</Button>
    </div>
  ),
}
