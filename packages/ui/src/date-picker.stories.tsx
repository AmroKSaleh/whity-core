import type { Meta, StoryObj } from "@storybook/react-vite"

import { DatePicker } from "./date-picker"

const meta = {
  title: "Primitives/DatePicker",
  component: DatePicker,
  tags: ["autodocs"],
  args: { placeholder: "Select date..." },
} satisfies Meta<typeof DatePicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <div className="w-72">
      <DatePicker />
    </div>
  ),
}

export const WithLabelAndTooltip: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-4">
      <DatePicker
        label="Event Start Date"
        required
        tooltip="Select the starting date for your workshop or webinar."
        helperText="Interactive calendar popover with month & year navigation."
      />
      <DatePicker
        label="Pre-selected Date"
        defaultValue={new Date("2026-10-24")}
        tooltip="Pre-selected date value."
        helperText="Includes clear button to reset date."
      />
    </div>
  ),
}

export const States: Story = {
  render: () => (
    <div className="flex w-72 flex-col gap-4">
      <DatePicker
        label="Active DatePicker"
        placeholder="Pick a date"
      />
      <DatePicker
        label="Disabled DatePicker"
        disabled
        defaultValue={new Date()}
        helperText="Disabled state prevents opening calendar."
      />
      <DatePicker
        label="Invalid DatePicker"
        errorText="Date must be in the future."
      />
    </div>
  ),
}
