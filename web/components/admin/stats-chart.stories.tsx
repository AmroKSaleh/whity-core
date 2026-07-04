import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { StatsChart } from "./stats-chart"

const series = Array.from({ length: 14 }, (_, i) => ({
  date: `2026-06-${String(i + 1).padStart(2, "0")}`,
  count: Math.round(20 + 15 * Math.sin(i / 2) + i),
}))

const meta = {
  title: "App/Admin/StatsChart",
  component: StatsChart,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
  args: { data: series, label: "Signups", color: "var(--primary)" },
} satisfies Meta<typeof StatsChart>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <div className="w-[32rem]">
      <StatsChart {...args} />
    </div>
  ),
}

export const Empty: Story = {
  args: { data: [], label: "Signups" },
  render: (args) => (
    <div className="w-[32rem]">
      <StatsChart {...args} />
    </div>
  ),
}
