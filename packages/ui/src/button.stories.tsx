import type { Meta, StoryObj } from "@storybook/react-vite"

import { Button } from "./button"

const meta = {
  title: "Primitives/Button",
  component: Button,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: ["default", "outline", "secondary", "ghost", "destructive", "link"],
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

const VARIANTS = ["default", "outline", "secondary", "ghost", "destructive", "link"] as const

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
      <Button size="icon-xs" aria-label="Add" icon="plus" />
      <Button size="icon-sm" aria-label="Add" icon="plus" />
      <Button size="icon" aria-label="Add" icon="plus" />
      <Button size="icon-lg" aria-label="Add" icon="plus" />
    </div>
  ),
}

export const WithIcons: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <Button icon="plus">New invoice</Button>
      <Button variant="outline" iconEnd="arrow-right">Continue</Button>
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
