import type { Meta, StoryObj } from "@storybook/react-vite"

import { Slider } from "./slider"

const meta = {
  title: "Primitives/Slider",
  component: Slider,
  tags: ["autodocs"],
  args: { defaultValue: [50], max: 100, step: 1 },
} satisfies Meta<typeof Slider>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <div className="w-80">
      <Slider defaultValue={[50]} />
    </div>
  ),
}

export const WithLabelAndTooltip: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-6">
      <Slider
        label="Volume Level"
        defaultValue={[75]}
        required
        tooltip="Adjust audio output volume level."
        formatValue={(val) => `${val}%`}
        helperText="Drag thumb or use arrow keys to adjust."
      />
      <Slider
        label="System Opacity"
        defaultValue={[40]}
        min={0}
        max={100}
        step={5}
        formatValue={(val) => `${val}%`}
        helperText="Adjust background opacity overlay."
      />
    </div>
  ),
}

export const RangeSlider: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-6">
      <Slider
        label="Price Range ($)"
        defaultValue={[20, 80]}
        min={0}
        max={100}
        step={5}
        formatValue={(val) => `$${val}`}
        helperText="Dual thumb range selector."
      />
    </div>
  ),
}

export const States: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-6">
      <Slider
        label="Active Slider"
        defaultValue={[60]}
      />
      <Slider
        label="Disabled Slider"
        defaultValue={[30]}
        disabled
        helperText="Disabled state."
      />
    </div>
  ),
}
