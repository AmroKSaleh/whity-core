import type { Meta, StoryObj } from "@storybook/react"
import { ColorPicker } from "./color-picker"

const meta: Meta<typeof ColorPicker> = {
  title: "Primitives/ColorPicker",
  component: ColorPicker,
  tags: ["autodocs"],
  argTypes: {
    value: { control: "color" },
    defaultValue: { control: "color" },
    label: { control: "text" },
    required: { control: "boolean" },
    tooltip: { control: "text" },
    helperText: { control: "text" },
    errorText: { control: "text" },
    disabled: { control: "boolean" },
    clearable: { control: "boolean" },
  },
}

export default meta
type Story = StoryObj<typeof ColorPicker>

export const Default: Story = {
  args: {
    placeholder: "Select color...",
    defaultValue: "#4f46e5",
  },
}

export const WithLabelAndTooltip: Story = {
  args: {
    label: "Brand Theme Color",
    required: true,
    tooltip: "Choose a primary accent color for your brand dashboard.",
    defaultValue: "#3b82f6",
    helperText: "Supports HEX, RGB, and color presets.",
  },
}

export const States: Story = {
  render: () => (
    <div className="flex flex-col gap-4 max-w-sm">
      <ColorPicker
        label="Empty State"
        placeholder="Choose accent color..."
      />
      <ColorPicker
        label="Selected Color"
        defaultValue="#10b981"
        helperText="Active theme color"
      />
      <ColorPicker
        label="Disabled State"
        defaultValue="#6366f1"
        disabled
      />
      <ColorPicker
        label="Error State"
        defaultValue="#ef4444"
        errorText="Invalid color selection"
      />
    </div>
  ),
}
