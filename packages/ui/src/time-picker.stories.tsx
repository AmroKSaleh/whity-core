import type { Meta, StoryObj } from "@storybook/react-vite"

import { TimePicker } from "./time-picker"

const meta = {
  title: "Primitives/TimePicker",
  component: TimePicker,
  tags: ["autodocs"],
  args: { placeholder: "Select time..." },
} satisfies Meta<typeof TimePicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <div className="w-72">
      <TimePicker />
    </div>
  ),
}

export const WithLabelAndTooltip: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-4">
      <TimePicker
        label="Meeting Time (12-Hour)"
        required
        tooltip="Select the scheduled meeting start time."
        helperText="Includes AM/PM toggle and scrollable hour/minute columns."
      />
      <TimePicker
        label="Shift Start (24-Hour)"
        format="24h"
        defaultValue="14:30"
        tooltip="24-hour military time format."
        helperText="Pre-selected time with clear button."
      />
    </div>
  ),
}

export const States: Story = {
  render: () => (
    <div className="flex w-72 flex-col gap-4">
      <TimePicker
        label="Active TimePicker"
        placeholder="Pick a time"
      />
      <TimePicker
        label="Disabled TimePicker"
        disabled
        defaultValue="09:00 AM"
        helperText="Disabled state prevents opening popover."
      />
      <TimePicker
        label="Invalid TimePicker"
        errorText="Selected time is outside working hours."
      />
    </div>
  ),
}
