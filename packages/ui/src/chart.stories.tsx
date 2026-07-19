import type { Meta, StoryObj } from "@storybook/react-vite"

import { Chart } from "./chart"

const meta = {
  title: "Primitives/Chart",
  component: Chart,
  tags: ["autodocs"],
} satisfies Meta<typeof Chart>

export default meta
type Story = StoryObj<typeof meta>

const monthly = [
  { month: "Jan", revenue: 186, cost: 120 },
  { month: "Feb", revenue: 205, cost: 130 },
  { month: "Mar", revenue: 237, cost: 145 },
  { month: "Apr", revenue: 173, cost: 110 },
  { month: "May", revenue: 209, cost: 128 },
]

const shareData = [
  { channel: "Direct", value: 42 },
  { channel: "Referral", value: 28 },
  { channel: "Search", value: 18 },
  { channel: "Social", value: 12 },
]

export const Bar: Story = {
  args: {
    type: "bar",
    data: monthly,
    xKey: "month",
    series: [
      { key: "revenue", label: "Revenue", color: 1 },
      { key: "cost", label: "Cost", color: 2 },
    ],
  },
}

export const Line: Story = {
  args: {
    type: "line",
    data: monthly,
    xKey: "month",
    series: [{ key: "revenue", label: "Revenue", color: 1 }],
  },
}

export const Area: Story = {
  args: {
    type: "area",
    data: monthly,
    xKey: "month",
    series: [{ key: "revenue", label: "Revenue", color: 1 }],
  },
}

export const Pie: Story = {
  args: {
    type: "pie",
    data: shareData,
    xKey: "channel",
    series: [{ key: "value", label: "Share", color: 1 }],
  },
}
