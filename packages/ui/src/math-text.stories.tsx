import type { Meta, StoryObj } from "@storybook/react-vite"

import { MathText } from "./math-text"

const meta = {
  title: "Primitives/MathText",
  component: MathText,
  tags: ["autodocs"],
  argTypes: {
    block: { control: "boolean" },
    errorColor: { control: "text" },
  },
  args: { expression: "\\frac{a}{b} + \\sqrt{x^2 + y^2}", block: false },
} satisfies Meta<typeof MathText>

export default meta
type Story = StoryObj<typeof meta>

export const Inline: Story = {
  render: (args) => (
    <p className="text-sm">
      The solution is <MathText {...args} /> for all real inputs.
    </p>
  ),
}

export const Block: Story = {
  args: { expression: "\\int_0^\\infty e^{-x^2}\\,dx = \\frac{\\sqrt{\\pi}}{2}", block: true },
}

export const RenderError: Story = {
  args: { expression: "\\frac{1}{", errorColor: "#e11d48" },
}
